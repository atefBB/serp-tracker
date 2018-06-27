<?php

/**
 * Simple SERP Tracker class.
 *
 * @see http://www.andreyvoev.com/simple-serp-tracker-php-class
 *
 * @copyright Andrey Voev 2011
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Andrey Voev <andreyvoev@gmail.com>
 * @author Atef Ben Ali <atef.bettaib@gmail.com>
 * @version 1.0
 *
 */
namespace SerpTracker;

abstract class Tracker
{
    // the url that we will use as a base for our search
    protected $baseurl;

    // the site that we are searching for
    protected $site;

    // the keywords for the search
    protected $keywords;

    // the current page the crawler is on
    protected $current;

    // starting time of the search
    protected $time_start;

    // debug info array
    protected $debug;

    // the limit of the search results
    protected $limit;

    // proxy file value
    protected $proxy;
    public $found;

    /**
     * Constructor function for all new tracker instances.
     *
     * @param array $keywords
     * @param string $site
     * @param int $limit OPTIONAL: number of results to search
     * @return Tracker
     */
    public function __construct(array $keywords, $site, $limit = 100)
    {
        // the keywords we are searching for
        $this->keywords = $keywords;

        // the url of the site we are checking the position of
        $this->site = $site;

        // set the maximum results we will search trough
        $this->limit = $limit;

        // setup the array for the results
        $this->found = array();

        // starting position
        $this->current = 0;

        // start benchmarking
        $this->time_start = microtime(true);

        // set the time limit of the script execution - default is 6 min.
        set_time_limit(360);

        // check if all the required parameters are set
        $this->initialCheck();
    }

    /**
     * Initial check if the base url is a string and if it has the required "keyword" and "position" keywords.
     */
    protected function initialCheck()
    {
        // get the model url from the extension class
        $url = $this->setBaseUrl();

        // check if the url is a string
        if (!is_string($url)) {
            die("The url must be a string");
        }

        // check if the url has the keyword and parameter in it
        $k = strpos($url, 'keyword');
        $p = strpos($url, 'position');
        if ($k === false || $p === false) {
            die("Missing keyword or position parameter in URL");
        }
    }

    /**
     * Set up the proxy if used.
     *
     * @param string $file OPTIONAL: if filename is not provided, the proxy will be turned off.
     */
    public function useProxy($file = false)
    {
        // the name of the proxy txt file if any
        $this->proxy = $file;

        if ($this->proxy != false) {
            if (file_exists($this->proxy)) {
                // get a proxy from a supplied file
                $proxies = file($this->proxy);

                // select a random proxy from the list
                $this->proxy = $proxies[array_rand($proxies)];
            } else {
                die("The proxy file doesn't exist");
            }
        }
    }

    /**
     * Parse the result from the crawler and pass the result html to the find function.
     *
     * @param string $single_url OPTIONAL: override the default url
     * @return string $result;
     */
    protected function parse(array $single_url = null)
    {
        // array of curl handles
        $curl_handles = array();
        // data to be returned
        $result = array();

        // multi handle
        $mh = curl_multi_init();

        // check if another URL is supplied
        $urls = ($single_url == null) ? $this->baseurl : $single_url;

        // loop through $data and create curl handles and add them to the multi-handle
        foreach ($urls as $id => $d) {
            $curl_handles[$id] = curl_init();

            $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
            curl_setopt($curl_handles[$id], CURLOPT_URL, $url);
            curl_setopt($curl_handles[$id], CURLOPT_HEADER, 0);
            curl_setopt($curl_handles[$id], CURLOPT_RETURNTRANSFER, 1);

            if ($this->proxy != false) {
                // use the selected proxy
                curl_setopt($curl_handles[$id], CURLOPT_HTTPPROXYTUNNEL, 0);
                curl_setopt($curl_handles[$id], CURLOPT_PROXY, $this->proxy);
            }

            // is it post?
            if (is_array($d)) {
                if (!empty($d['post'])) {
                    curl_setopt($curl_handles[$id], CURLOPT_POST, 1);
                    curl_setopt($curl_handles[$id], CURLOPT_POSTFIELDS, $d['post']);
                }
            }

            // are there any extra options?
            if (!empty($options)) {
                curl_setopt_array($curl_handles[$id], $options);
            }

            curl_multi_add_handle($mh, $curl_handles[$id]);
        }

        // execute the handles
        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        // get content and remove handles
        foreach ($curl_handles as $id => $c) {
            $result[$id] = curl_multi_getcontent($c);
            curl_multi_remove_handle($mh, $c);
        }

        // close curl
        curl_multi_close($mh);

        // return the resulting html
        return $result;
    }

    /**
     * Crawl trough every page and pass the result to the find function until all the keywords are processed.
     */
    protected function crawl()
    {
        $this->setup();
        $html = $this->parse();

        $i = 0;
        foreach ($html as $single) {
            $result = $this->find($single);

            if ($result !== false) {
                if (!isset($this->found[$this->keywords[$i]])) {
                    $this->found[$this->keywords[$i]] = $this->current + $result;

                    // save the time it took to find the result with this keyword
                    $this->debug['time'][$this->keywords[$i]] = number_format(microtime(true) - $this->time_start, 3);

                    unset($this->keywords[$i]);
                }

                // remove the keyword from the haystack
                unset($this->keywords[$i]);
            }
            $i++;
        }

        if (!empty($this->keywords)) {
            if ($this->current <= $this->limit) {
                $this->current += 10;
                $this->crawl();
            }
        }
    }

    /**
     * Prepare the array of the keywords for every run.
     */
    protected function setup()
    {
        // prepare the url array for the new loop
        unset($this->baseurl);

        foreach ($this->keywords as $keyword) {
            $url             = $this->setBaseUrl();
            $url             = str_replace("keyword", $keyword, $url);
            $url             = str_replace("position", $this->current, $url);
            $this->baseurl[] = $url;
        }
    }

    /**
     * Start the crawl/search process.
     */
    public function run()
    {
        $this->crawl();
    }

    /**
     * Return the results from the search.
     *
     * @return array
     */
    public function getResults()
    {
        return $this->found;
    }

    /**
     * Return the debug information - time taken, etc.
     *
     * @return array
     */
    public function getDebugInfo()
    {
        return $this->debug;
    }

    /**
     * Set up the base url for the specific search engine using "keyword" and "position" for setting up the template.
     *
     * @return string $baseurl;
     */
    abstract public function setBaseUrl();

    /**
     * Find the occurrence of the site in the results page. Specific for every search engine.
     *
     * @param string $html OPTIONAL: override the default html if needed
     * @return string;
     */
    abstract public function find($html);
}
