# -*- coding: utf-8 -*-
import scrapy
from urllib.parse import urljoin


class MusspiderSpider(scrapy.Spider):
    name = "musspider"
    allowed_domains = ["musicteacher.com.au"]
    start_urls = (
        'http://www.musicteacher.com.au/directory/',
    )
    categories={'Fiddle Lessons'} #,'Fife Lessons'}
    debug_count=0

    def category_filter(self, category):
        if len(self.categories) == 0:
            return True
        return category in self.categories

    def construct_category_req(self, node, category=None, callback=None):
        url=urljoin('http://www.musicteacher.com.au',node.xpath('@href').extract_first())
        req=scrapy.Request(url,callback=callback or self.parse_category)
        req.meta['category']=category or node.xpath('text()').extract_first()
        return req

    # parse categories from main page and yield requests
    def parse(self, response):
        s=scrapy.Selector(response)
        roots=s.css('div.main ul.no_bullet > li > a')
        for root in roots:
            #self.debug_count+=1
            #if self.debug_count>1:
            #    break
            sub=root.xpath('parent::node()/ul/li/a')
            if len(sub)>0:
                for a in sub:
                    if self.category_filter(a.xpath('text()').extract_first()):
                        yield self.construct_category_req(a)
            else:
                if self.category_filter(root.xpath('text()').extract_first()):
                    yield self.construct_category_req(root)

    # parse items urls
    def parse_category(self, response):
        c=scrapy.Selector(response)
        self.savefile(response.meta['category']+".html", response.body)
        if len(c.css("div.main > div.msg_alert"))>0:
            yield from self.generate_from_location_reqs(response.meta['category'], c)
        else:
            yield from self.generate_items_urls(response.meta['category'], c)

    def generate_from_location_reqs(self, category, c):
        locations=c.css("li.two_column > a")
        for loc in locations:
            yield self.construct_category_req(loc, category)

    def generate_items_urls(self, category, c):
        items_urls=c.css("div.detail > div.service > a")
        for url in items_urls:
            self.logger.warning("to parse %s" % url.xpath("text()").extract_first())
            yield self.construct_category_req(url,category, self.parse_item)

    def parse_item(self, response):
        i=dict()
        i['category']=response.meta['category']
        i['url']=response.url
        yield i

    def savefile(self, filename, content):
        with open(filename,'w') as file:
            print(content, file=file)

