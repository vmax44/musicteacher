# -*- coding: utf-8 -*-
import scrapy
from urllib.parse import urljoin


class MusspiderSpider(scrapy.Spider):
    name = "musspider"
    allowed_domains = ["musicteacher.com.au"]
    start_urls = (
        'http://www.musicteacher.com.au/directory/',
    )
    debug_count=0

    def construct_category_req(self, node, category=None, callback=None):
        url=urljoin('http://www.musicteacher.com.au',node.xpath('@href').extract_first())
        req=scrapy.Request(url,callback=callback or self.parse_category)
        req.meta['category']=category or node.xpath('text()').extract_first()
        return req

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
                    yield self.construct_category_req(a)
            else:
                yield self.construct_category_req(root)

    def parse_category(self, response):
        c=scrapy.Selector(response)
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
            yield self.construct_category_req(url,category, self.parse_item)

    def parse_item(self, response):
        i=dict()
        i['category']=response.meta['category']
        i['url']=response.url
        yield i