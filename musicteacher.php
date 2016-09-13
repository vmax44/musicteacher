<?php

/**
 * Description of site
 *
 * @author maxim
 */
use Symfony\Component\DomCrawler\Crawler;

class MusicTeacher {

    private $http;
    private $html;
    private $doc;
    private $log;
    private $details = null;
    private $teachers = null;
    private $lastParsedPage = 0;

    public function __construct(\GuzzleHttp\Client $client, Crawler $doc, Logger $logger) {
        $this->http = $client;  // \GuzzleHttp\Client
        $this->doc = $doc;      // \Symfony\Component\DomCrawler
        $this->log = $logger;   // Logger
    }

    public function getLocations($filter = []) {
        $this->doc = new Crawler($this->getPage('/directory/'));
        return $this->parseLocations($filter, $this->doc);
    }

    private function parseLocations($filter, Crawler $doc) {
        $locations = [];
        $doc->filter("li.two_column > a")->
                each(function($node, $i) use (&$locations, $filter) {
                    if (count($filter) == 0 || in_array($node->text(), $filter)) {
                        $locations[] = [
                            'url' => $node->attr('href'),
                            'name' => $node->text()
                        ];
                    }
                });

        return $locations;
    }

    public function getMusicServicesAsync($locations) {
        $this->details = [];
        $count = 0;
        $requests = function ($itemUrls) {
            for ($i = 0; $i < count($itemUrls); $i++) {
                $this->log->add("      loading item {$itemUrls[$i]['name']}");
                yield function() use ($itemUrls, $i) {
                    return $this->getPageAsync($itemUrls[$i]['url']);
                };
            }
        };

        $pool = new \GuzzleHttp\Pool($this->http, $requests($locations), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$count, $locations) {
                // this is delivered each successful response
                $this->log->add("      load Ok item {$locations[$index]['name']}");

                $parsed = $this->parseMusicServices($locations[$index]['name'], new Crawler((string) $response->getBody()));

                //$this->details[$locations[$index]['name']] = $parsed;
                $this->details=  array_merge($this->details, $parsed);
                $this->log->add("      parsed item {$locations[$index]['name']}");
                $count++;
            },
            'rejected' => function ($reason, $index) use ($locations) {
                // this is delivered each failed request
                $this->log->add("      Error parsing item {$locations[$index]['name']}: " . $reason->getMessage());
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $this->details;
    }

    /*
    public function getMusicServices($locationUrl) {
        $this->doc = new Crawler($this->getPage($locationUrl));
        return $this->parseMusicServices($this->doc);
    }*/

    private function parseMusicServices($location, Crawler $doc) {
        $services = [];
        $cat1 = $doc->filter('div.main ul.no_bullet > li > a');
        $cat1->each(function(Crawler $cmain, $imain) use (&$services, $location) {
            $cat2 = $cmain->parents()->eq(0)->filter("ul > li > a");
            if (count($cat2) > 0) {   // if there are subcategories
                $cat2->each(function(Crawler $c, $i) use (&$services, $location) {
                    $s['url'] = $c->attr('href');
                    $s['name'] = $c->text();
                    $s['location'] = $location;
                    $services[] = $s;
                });
            } else {  // if no subcategories
                $s['url'] = $cmain->attr('href');
                $s['name'] = $cmain->text();
                $s['location'] = $location;
                $services[] = $s;
            }
        });
        return $services;
    }

    public function getTeacherUrlsAsync(&$services) {
        $scraped=[];
        $this->teachers=new ParsedData("teachers.csv");
        $requests = function ($itemUrls) {
            for ($i = 0; $i < count($itemUrls); $i++) {
                $this->log->add("      loading item {$itemUrls[$i]['name']}");
                yield function() use ($itemUrls, $i) {
                    return $this->getPageAsync($itemUrls[$i]['url']);
                };
            }
        };

        $pool = new \GuzzleHttp\Pool($this->http, $requests($services), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$services, &$scraped) {
                // this is delivered each successful response
                //$this->log->add("      load Ok item {$services[$index]['name']}");

                $parsed = $this->parseTeacherUrls(new Crawler((string) $response->getBody()));
                $category=$services[$index]['name'];
                foreach($parsed as $teacher) {
                    if(!array_key_exists($teacher['name'], $scraped)) {
                        $scraped[$teacher['name']]=[
                            'url'=>$teacher['url'],
                            'category'=>[]
                        ];
                    }
                    if(!in_array($category, $scraped[$teacher['name']]['category'])) {
                        $scraped[$teacher['name']]['category'][]=$category;
                    }
                }
                //$this->log->add("      parsed item {$services[$index]['name']}");
                $this->log->add("      parsed ".count($parsed)." teachers");
                $count+=count($parsed);
            },
            'rejected' => function ($reason, $index) use ($services) {
                // this is delivered each failed request
                $this->log->add("      Error parsing item {$services[$index]['name']}: " . $reason->getMessage());
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
        
        $idx=0;
        //foreach($scraped as $name=>$details) {
           
        //}
        
        $this->teachers->toDisk(true);
        $this->saveServicesAsCSV($scraped, "scraped.csv");
        
        return $scraped;
    }
    
    public function scrapeFullInfo(&$teacherUrls) {
        
    }

    private function saveServicesAsCSV(&$services,$fileName,$needheaders=true) {
        if (count($services) <= 0)
            return ;
        $fp = fopen($fileName, "w");
        if ($needheaders) {
            $headers = array_keys(each($services)[1]);
            array_unshift($headers,"name");
            fputcsv($fp, $headers);
        }
        foreach ($services as $name=>$item) {
            foreach($item as $idx=>$it) {
                if(is_array($it)) {
                    $item[$idx]=  implode("|", $it);
                }
            }
            array_unshift($item,$name);
            fputcsv($fp, $item);
        }
        fclose($fp);
    }
    
    private function parseTeacherUrls(Crawler $doc) {
        $teacherUrls = [];
        $path=[];
        $doc->filter("div.breadcrumb > a")
                ->each(function($a,$index) use (&$path) {
                    $path[]=$a->text();
                });
        $doc->filter("div.detail")
                ->each(function (Crawler $box, $index) use (&$teacherUrls, $path) {
                    $t = [];
                    $t['name'] = $box->filter("div.list_head > span.name")->text();
                    $t['suburb']=$box->filter("div.list_head > span.suburb")->text();
                    $t['state']=$box->filter("div.list_head > span.state")->text();
                    $t['url'] = $box->filter("div.service > a")->attr('href');
                    $t['service'] = $box->filter("div.service > a")->text();
                    $t['path']=$path;
                    $this->teachers->add($t['name'],$t['url']);
                    $teacherUrls[] = $t;
                });
        return $teacherUrls;
    }

    private function extractNumber($text) {
        $matches = [];

        if (preg_match("\(([0-9]+)\)", $text, $matches)) {
            return $matches[1];
        } else {
            return 0;
        }
    }

    public function scrape(ParsedData $data, $filter) {
        $this->details = $data;
        $this->log->add("Start scrape filter: $filter");
        $page = 1;
        do {
            /*
              if ($page > 1)
              break;
             */

            $this->log->add("  scrape page $page");
            try {
                $info = $this->getByRegionId("163", $filter, $page);
                $this->log->add("  done. Parsed " . $info['parsedItemsCount'] . " items");
                $this->lastParsedPage = $page;
            } catch (Exception $e) {
                $this->log->add('  Error: ' . $e->getMessage());
            }
        } while ($page++ < $info['pageCount']);
        $this->details
                ->toDisk()
                ->clear();
    }

    private function getByRegionId($regionId, $filter = "", $page = 1) {
        $f = $filter == "" ? "" : $filter . "/";
        $url = "https://www.homeaway.com/ajax/map/search/massachusetts/marthas-vineyard/region:$regionId/$f@,,,,z/page:$page?view=l";
        $this->log->add('    downloading page');
        $data = $this->getPage($url, true);
        $json = json_decode($data);
        $itemsUrls = $this->itemsUrlsParse($json);
        $this->log->add('    items to parse - ' . count($itemsUrls));
        $i = $this->parseUrlsAsync($itemsUrls);

        return [
            'fromRecord' => $json->results->fromRecord,
            'hitCount' => $json->results->hitCount,
            'pageCount' => $json->results->pageCount,
            'parsedItemsCount' => $i
        ];
    }

    private function parseUrlsAsync($itemsUrls) {

        $count = 0;
        $requests = function ($itemUrls) {
            for ($i = 0; $i < count($itemUrls); $i++) {
                $this->log->add("      loading item {$itemUrls[$i]['id']}");
                yield function() use ($itemUrls, $i) {
                    return $this->getPageAsync("https://www.homeaway.com" . $itemUrls[$i]['url']);
                };
            }
        };

        $pool = new \GuzzleHttp\Pool($this->http, $requests($itemsUrls), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$count, $itemsUrls) {
                // this is delivered each successful response
                $this->log->add("      load Ok item {$itemsUrls[$index]['id']}");
                $this->itemFullParse($itemsUrls[$index]['id'], $response->getBody());
                $this->log->add("      parsed item {$itemsUrls[$index]['id']}");
                $count++;
            },
            'rejected' => function ($reason, $index) use ($itemsUrls) {
                // this is delivered each failed request
                $this->log->add("      Error parsing item {$itemsUrls[$index]['id']}: " . $reason->getMessage());
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $count;
    }

    private function itemsUrlsParse($json) {
        $urls = [];
        foreach ($json->results->hits as $item) {
            $id = $item->propertyId;
            $p = [];
            $urls[] = [
                'id' => $id,
                'url' => $item->detailPageUrl
            ];
            $p['id'] = $id;
            $p['url'] = $item->detailPageUrl;
            $p['title'] = trim($item->headline);
            $p['BR'] = $item->bedrooms;
            $p['BA'] = $item->bathrooms->full;
            $p['HF BA'] = $item->bathrooms->half;
            $p['TO'] = $item->bathrooms->toiletOnly;
            $p['sleeps'] = $item->sleeps;
            $p['propertyType'] = $item->propertyType;
            $p['minStay'] = $item->minStayRange->minStayLow . "-" . $item->minStayRange->minStayHigh;
            $p['Avg price'] = $item->averagePrice->value . "/" . $item->averagePrice->periodType;
            $p['latitude'] = $item->geoCode->latitude;
            $p['longitude'] = $item->geoCode->longitude;

            $this->details->add($id, $p);
        }
        return $urls;
    }

    private function itemFullParse($id, $resp) {
        try {
            //$resp = $this->getPage("https://www.homeaway.com" . $url);
            $this->doc->clear();
            $this->doc->load($resp);
            //check if loaded right page
            if (is_null($this->doc->find("div[class='property-description']", 0))) {
                throw new Exception("      not found property description - not right page?");
            }
            $matches = [];
            if (!preg_match("/'pageData', \[\],\s+(\{.+\})/", $resp, $matches)) {
                throw new Exception("      not found property details - we are blocked?");
            }
            $pageData = json_decode($matches[1]);
            $d = &$this->details->getToUpdate($id);
            $d['description'] = $this->findtext("div[class='property-description'] > div[class='prop-desc-txt']");
            $d['image'] = $pageData->listing->images[0]->imageFiles[0]->uri;
            $d['contact name'] = $pageData->listing->contact->name;
            $d['contact phones'] = "";
            foreach ($pageData->listing->contact->phones as $phone) {
                $d['contact phones'] = $d['contact phones'] . $phone->telScheme . ";";
            }
            $d['ownermanaged'] = $pageData->listing->ownerManaged == 1;

            //$this->log->add("      done");
        } catch (Exception $e) {
            $this->log->add("      Error: " . $e->getMessage());
        }
        $t = '';
    }

    public function goHome() {
        $this->doc->load($this->getPage('/directory/'));
        return $this->doc;
    }

    private function getPage($url, $ajax = false) {
        $resp = $this->http->request('GET', $url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36',
                'X-Requested-With' => $ajax ? 'XMLHttpRequest' : ''
            ]
        ]);
        $this->html = $resp->getBody()->getContents();
        return $this->html;
    }

    private function getPageAsync($url, $ajax = false) {
        $promise = $this->http->getAsync($url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36',
                'X-Requested-With' => $ajax ? 'XMLHttpRequest' : ''
            ]
        ]);
        return $promise;
    }

    private function cleantext($str) {
        return trim(preg_replace('/\s+/S', " ", $str));
    }

    private function findtext($selector) {
        $node = $this->doc->find($selector, 0);
        if (isset($node->plaintext)) {
            return $this->cleantext($node->plaintext);
        } else {
            return "";
        }
    }

}
