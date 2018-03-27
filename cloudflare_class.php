<?php
/**
 * Manage DNS records & server settings from CloudFlare
 *
 * @author     Kyle
 * @created    13/03/04 18:12
 * @modified   $Date: 2013-03-08 08:41:37 -0800 (金, 08 3 2013) $
 * @revision   $Revision: 58 $
 */

class Cloudflare
{
    /**
     * API Key to CloudFlare account
     */
    private $apikey = '';	

    /**
     * The email associated with the account
     */
    private $email = '';

    /**
     * The domain we wish to query through the API
     */
    private $domain = '';

    /**
     * The max length of a sub-domain
     */
    private $sub_max_length = 4;

    /**
     * A list of sub domains we do not want added or deleted
     */
    private $reject = array(
        'www', 'mail', 'forum', 'forums', 'ssl', 'smtp', 'admin', 'login', 'nano'
    );
	
    /**
     * The URL to access CloudFlares API
     */
    private $cloudflare = 'https://www.cloudflare.com/api_json.html';

    /**
     * Construct the class to do nothing
     */
    function __construct()
    {
    }

    /**
     * Override the default domain
     */
    function setDomain($domain)
    {
        $this->domain = $domain;
    }

    /**
     * Override the default api key
     */
    function setKey($api_key)
    {
        $this->apikey = $api_key;
    }

    /**
     * Override the default email
     */
    function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * Instruct CloudFlare to purge the cached files on our domain
     * Log any errors into error log
     *
     * return null
     */
    function purge()
    {
        $fields = http_build_query(
            array(
                'a' => 'fpurge_ts',
                'tkn' => $this->apikey,
                'email' => $this->email,
                'z' => $this->domain,
                'v' => '1'
            )
        );

        $result = $this->send($fields);
        $result = json_decode($result, true);
        $this->logErrors($result);
    }

    /**
     * Instruct CloudFlare to add a new CNAME record into our DNS.
     *
     * `service_mode` = 1 turns on CloudFlare proxying
     *
     *  return bool
     */
    function add($cname)
    {
        if (!ctype_alnum($cname)) {
            return false;
        }
        if (in_array($cname, $this->reject)) {
            return false;
        }

        if (strlen($cname) < $this->sub_max_length) {
            return false;
        }

        $fields = http_build_query(
            array(
                'a' => 'rec_new',
                'tkn' => $this->apikey,
                'email' => $this->email,
                'z' => $this->domain,
                'type' => 'CNAME',
                'name' => $cname,
                'content' => $this->domain,
                'ttl' => '1'
            )
        );

        $result = $this->send($fields);
        $result = json_decode($result, true);

        $response = isset($result['msg']) ? $result['msg'] : '';

        if ($response == 'A record with these exact values already exists. Please modify or remove this record.') {
            return false;
        } else {
            $records = $this->records();

            $arrays = isset($records['response']['recs']['objs']) ? $records['response']['recs']['objs'] : null;
            $result = is_array($arrays) ? $this->searchSubArray($arrays, 'display_name', $cname) : false;

            if ($result) {
                $record_id = $records['response']['recs']['objs'][$result]['rec_id'];

                $fields = http_build_query(
                    array(
                        'a' => 'rec_edit',
                        'tkn' => $this->apikey,
                        'id' => $record_id,
                        'email' => $this->email,
                        'z' => $this->domain,
                        'type' => 'CNAME',
                        'name' => $cname,
                        'content' => $this->domain,
                        'service_mode' => '1',
                        'ttl' => '1'
                    )
                );

                $result = $this->send($fields);
                $result = json_decode($result, true);
                $this->logErrors($result);

                return true;
            }

            $this->logErrors($records);
            return false; //unexpected error has occurred
        }
    }

    /**
     * Delete a DNS record from CloudFlare.
     *
     * Retrieves a list of all the DNS records and searches for the CNAME $name
     *
     * When the record_id is found, tell CloudFlare to delete it.
     *
     * return bool
     */
    function delete($name)
    {
        if (!ctype_alnum($name)) {
            return false;
        }

        if (in_array($name, $this->reject)) {
            return false;
        }

        if (strlen($name) < $this->sub_max_length) {
            return false;
        }

        $records = $this->records();

        $arrays = isset($records['response']['recs']['objs']) ? $records['response']['recs']['objs'] : null;
        $result = is_array($arrays) ? $this->searchSubArray($arrays, 'display_name', $name) : false;

        if ($result) {
            $record_id = $records['response']['recs']['objs'][$result]['rec_id'];
        } else {
            $this->logErrors($records);
            return false;
        }

        $fields = http_build_query(
            array(
                'a' => 'rec_delete',
                'tkn' => $this->apikey,
                'email' => $this->email,
                'z' => $this->domain,
                'id' => $record_id
            )
        );

        $result = $this->send($fields);
        $result = json_decode($result, true);
        $this->logErrors($result);

        return true;
    }

    /**
     * Retrieve the DNS list from CloudFlare.
     *
     * Store the results into an associative array.
     *
     * return assoc array
     */
    private function records()
    {
        $fields = http_build_query(
            array(
                'a' => 'rec_load_all',
                'tkn' => $this->apikey,
                'email' => $this->email,
                'z' => $this->domain
            )
        );

        $result = $this->send($fields);
        $this->logErrors($result);

        return json_decode($result, true);
    }

    /**
     * Function to process communicating with cURL
     */
    private function send($params)
    {
        $send = curl_init($this->cloudflare);

        curl_setopt($send, CURLOPT_HEADER, 0);
        curl_setopt($send, CURLOPT_HTTPHEADER, array('Expect:'));
        curl_setopt($send, CURLOPT_POST, 1);
        curl_setopt($send, CURLOPT_POSTFIELDS, $params);
        curl_setopt($send, CURLOPT_USERAGENT, "Mozilla/6.0 (Windows NT 6.2; WOW64; rv:16.0.1) Gecko/20121011 Firefox/16.0.1");
        curl_setopt($send, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($send);
        curl_close($send);

        return $result;
    }

    /**
     * Log any errors passed from json
     */
    private function logErrors($json)
    {
        if (isset($json['msg'])) {
            error_log('[CloudFlare API Error] ' . $json['msg']);
        }
    }

    /**
     * Function to search efficiently through a 2D associative array
     */
    private function searchSubArray($array, $assoc, $value)
    {
        foreach ($array as $key => $sub) {
            if (isset($sub[$assoc]) && $sub[$assoc] == $value)
                return $key;
        }
        return false;
    }
}