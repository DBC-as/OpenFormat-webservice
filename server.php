<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/

//-----------------------------------------------------------------------------
require_once('OLS_class_lib/webServiceServer_class.php');
require_once 'OLS_class_lib/memcache_class.php';
require_once 'OLS_class_lib/cql2solr_class.php';

//-----------------------------------------------------------------------------
define(DEBUG_ON, FALSE);

//-----------------------------------------------------------------------------
class openFormat extends webServiceServer {
    protected $curl;                      // the curl connection
    protected $fake_multi;                // for performance testing purposes
    protected $record_blocking;           // block factor: number of recs in each request to js_server
    protected $js_server_url = array ();  // if more than one, the formatting requests will be split amongst them
    protected $current_js_server;         // js_server to use next
    protected $rec_status = array();      // curl_status for each rec formattet

    public function __construct(){
        webServiceServer::__construct('openformat.ini');

        $this->curl = new curl();
        foreach ($this->config->get_value('js_server', 'setup') as $url)
            $this->js_server_url[] = $url;
        if (!($this->record_blocking = (integer) $this->config->get_value('record_blocking', 'setup')))
            $this->record_blocking = 1;
    }

    /**
        \brief Handles the request and set up the response
    */

    public function format($param) {
        if (!$this->aaa->has_right('openformat', 500))
            $res->error->_value = 'authentication_error';
        else {
            $param->trackingId->_value = verbose::set_tracking_id('of', $param->trackingId->_value);
            if (is_array($param->originalData))
                foreach ($param->originalData as $key => $od)
                    $form_req[] = &$param->originalData[$key];
            else
                $form_req[] = &$param->originalData;
            $formatted = $this->format_recs($form_req, $param);

        }
        for ($i = 0; $i < count($formatted); $i++) {
            $fkey = key($formatted[$i]);
            $res->{$fkey}[] = &$formatted[$i]->$fkey;
        }
        $ret->formatResponse->_value = &$res;
        $ret->formatResponse->_namespace = $this->xmlns['of'];
        if (!($dump_format = $this->dump_timer)) $dmp_format = '%s';
        foreach ($this->rec_status as $r_c) {
            $size_upload = $r_c['size_upload'];
            $size_download = $r_c['size_download'];
        }
        verbose::log(STAT, sprintf($dump_format.'::', 'format') . 
                           ' Ip:' . $_SERVER['REMOTE_ADDR'] . 
                           ' Format:' . $param->outputFormat->_value . 
                           ' NoRec:' . count($form_req) .
                           ' bytesIn: ' . $size_upload .
                           ' bytesOut: ' . $size_download .
                           ' no_of_js_server:' . count($this->js_server_url) . 
                           ' js_server:' . sprintf('%01.3f', $this->watch->splittime('js_server')) .
                           ' Total:' . sprintf('%01.3f', $this->watch->splittime('Total')));
        //var_dump($ret); var_dump($param); die();
        return $ret;

    }

    /** \brief Format recs sending them to js_server(s)
     *
     */
    private function format_recs(&$recs, &$param) {
        // Since the api for record_blocking is not defined, it will be set to 1
        $this->record_blocking = 1;

        if (!($timeout = (integer) $this->config->get_value('curl_timeout', 'setup')))
            $timeout = 5;

        $dom = new DomDocument();
        $dom->preserveWhiteSpace = false;
        $output_format = $param->outputFormat->_value;
        $form_req->formatSingleManifestationRequest->_value->agency = $param->agency;
        $form_req->formatSingleManifestationRequest->_value->language = $param->language;
        $form_req->formatSingleManifestationRequest->_value->outputFormat = $param->outputFormat;
        $form_req->formatSingleManifestationRequest->_value->trackingId = $param->trackingId;
        $ret = array();
        $ret_index = array();  // to make future caching easier
        $curls = 0;
        $tot_curls = 0;
        $next_js_server = rand(0, count($this->js_server_url) - 1);
        for ($no = 0; $no < count($recs); $no = $no + $this->record_blocking) {
            $ret_index[$curls] = $no;
            $form_req->formatSingleManifestationRequest->_value->originalData = &$recs[$tot_curls];
            $this->curl->set_option(CURLOPT_TIMEOUT, $timeout, $curls);
            $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8'), $curls);
            $this->curl->set_url($this->js_server_url[$next_js_server], $curls);
            $rec = $this->objconvert->obj2xmlNs($form_req);
            $this->curl->set_post_xml($rec, $curls);
            //verbose::log(DEBUG, 'Using js_server no: ' . $next_js_server);
            $curls++;
            $tot_curls++;
            if ($curls == count($this->js_server_url) || $tot_curls == count($recs)) {
                //verbose::log(DEBUG, "Do curl");
                $this->watch->start('js_server');
                $js_result = $this->curl->get();
                $curl_status = $this->curl->get_status();
                $this->watch->stop('js_server');
                if ($curl_status['url']) $curl_status = array($curl_status);
                if (!is_array($js_result)) $js_result = array($js_result);
                for ($i = 0; $i < $curls; $i++) {
                    $this->rec_status[] = $curl_status[$i];
                    if ($curl_status[$i]['http_code'] == 200) {
                        if (@ $dom->loadXML($js_result[$i]))
                            $js_obj = $this->xmlconvert->xml2obj($dom);
                        else
                            $error = 'Error formatting record - no valid response';
                    } else {
                        verbose::log(ERROR, 'http code: ' . $curl_status[$i]['http_code'] . 
                                            ' error: "' . $curl_status[$i]['error'] .
                                            '" for: ' . $curl_status[$i]['url'] .
                                            ' TId: ' . $param->trackingId->_value);
                        $error = 'HTTP error ' . $curl_status[$i]['http_code'] . ' . formatting record';
                    }
                    if ($error) {
                        $js_obj->{$output_format}->_namespace = $this->xmlns['of'];
                        $js_obj->{$output_format}->_value->error->_value = $error;
                        $js_obj->{$output_format}->_value->error->_namespace = $this->xmlns['of'];
                        unset($error);
                    }
                    $ret[$ret_index[$i]] = $js_obj;
                }
                $curls = 0;
            }
            $next_js_server = ++$next_js_server % count($this->js_server_url);
        }
        return $ret;
    }

    /** \brief Echos config-settings
     *
     */
    public function show_info() {
        echo '<pre>';
        echo 'version             ' . $this->config->get_value('version', 'setup') . '<br/>';
        echo 'logfile             ' . $this->config->get_value('logfile', 'setup') . '<br/>';
        echo 'verbose             ' . $this->config->get_value('verbose', 'setup') . '<br/>';
        $txt = 'js_server           ';
        foreach ($this->config->get_value('js_server', 'setup') as $js) {
            echo $txt . $js . '<br/>';
            $txt = ' -                  ';
        }
        echo 'aaa_credentials     ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
        echo 'pwd                 ' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        echo '</pre>';
        die();
    }

    /** \brief
     *  hides password part of oci credentials
     */
    private function strip_oci_pwd($cred) {
        if (($p1 = strpos($cred, '/')) && ($p2 = strpos($cred, '@')))
            return substr($cred, 0, $p1) . '/********' . substr($cred, $p2);
        else
            return $cred;
    }


    /** \brief
     *  return libraryno - align to 6 digits
     */
    private function normalize_agency($id) {
        if (is_numeric($id))
            return sprintf('%06s', $id);
        else
            return $id;
    }

    /** \brief
     *  return only digits, so something like DK-710100 returns 710100
     */
    private function strip_agency($id) {
        return preg_replace('/\D/', '', $id);
    }


}

/*
 * MAIN
 */

    $ws=new openFormat();
    $ws->handle_request();
?>

