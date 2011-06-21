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
    protected $curl;

    public function __construct(){
        webServiceServer::__construct('openformat.ini');

/*
        if (!$timeout = $this->config->get_value('curl_timeout', 'setup'))
            $timeout = 20;
        $this->curl = new curl();
        $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);
*/
    }

    /**
        \brief Handles the request and set up the response
    */

    public function formatSingleManifestation($param) {

        //var_dump($res); var_dump($param); die();
        $ret->formatSingleManifestationResponse->_value = $res;
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
        echo 'aaa_credentials     ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
        echo '</pre>';
        die();
    }

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

