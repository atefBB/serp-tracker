<?php

namespace SerpTracker;

use SerpTracker\Tracker;

class GoogleTracker extends Tracker
{
    public function setBaseUrl()
    {
        // use "keyword" and "position" to mark the position of the variables in the url
        $baseurl = "http://www.google.com/search?q=keyword&start=position";
        return $baseurl;
    }

    public function find($html)
    {
        // process the html and return either a numeric value of the position of the site in the current page or FALSE
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $nodes = $dom->getElementsByTagName('cite');

        // found is false by default, we will set it to the position of the site in the results if found
        $found = false;

        // start counting the results from the first result in the page
        $current = 1;
        foreach ($nodes as $node) {

            $node = $node->nodeValue;
            // look for links that look like this: cmsreport.com › Blogs › Bryan's blog
            if (preg_match('/\s/', $node)) {
                $site = explode(' ', $node);
            } else {
                $site = explode('/', $node);
            }

            $urls[$current] = $site[0];

            if ($site[0] == $this->site) {
                $found = true;
                $place = $current;
            }
            $current++;
        }

        if (isset($found) && $found !== false) {
            return $place;
        } else {
            return false;
        }
    }
}
