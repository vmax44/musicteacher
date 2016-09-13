# -*- coding: utf-8 -*-
import scrapy
from urllib.parse import urljoin


class MusspiderSpider(scrapy.Spider):
    name = "musspider"
    allowed_domains = ["musicteacher.com.au"]
    start_urls = (
        'http://www.musicteacher.com.au/directory/',
    )

    def construct_category_req(self, node):
        url=urljoin('http://www.musicteacher.com.au',node.xpath('@href').extract_first())
        req=scrapy.Request(url,callback=self.parse_category)
        req.meta['category']=node.xpath('text()').extract_first()
        return req

    def parse(self, response):
        s=scrapy.Selector(response)
        roots=s.css('div.main ul.no_bullet > li > a')
        for root in roots:
            sub=root.xpath('parent::node()/ul/li/a')
            if len(sub)>0:
                for a in sub:
                    yield self.construct_category_req(a)
            else:
                yield self.construct_category_req(root)

    def parse_category(self, response):
        i=dict()
        i['category']=response.meta['category']
        i['url']=response.url
        yield i