<?php

/**
 * Made with ❤ by vuefeed
 *
 * XML Feed generation
 */

class feedcomet_api_client
{
    const BASE_DOMAIN = 'http://feedcomet.com/';
    const API_SOURCE_URL = self::BASE_DOMAIN . 'api/products/v1/products/source';
    const API_ADD_PRODUCTS_URL = self::BASE_DOMAIN . 'api/products/v1/products/';
    const OPTION_SOURCE = 'feedcomet_source';
    const OPTION_TOKEN = 'feedcomet_token';

    protected $token;
    protected $source_id;

    /**
     * Create FeedComet Api client
     *
     * @param string $token Authorization token
     */
    public function __construct()
    {
        $this->token = get_option(self::OPTION_TOKEN, '');;
        $this->source_id = $this->initialize_source_id();
    }

    /**
     * Get access token
     *
     * @return string Access Token for provided client
     */
    protected function initialize_source_id()
    {
        if (!$this->token) {
            return '';
        }

        $source_id = get_option(self::OPTION_SOURCE, '');

        if (!$source_id) {
            $response = wp_remote_get(
                self::API_SOURCE_URL . '?eic=' . $this->get_plugin_id(),
                array(
                    'headers' => array('PluginToken' => $this->token),
                )
            );

            if (!is_wp_error($response)) {
                update_option(self::OPTION_SOURCE, $response['body']);
                $source_id = $response['body'];
            }
        }

        return $source_id;
    }

    public function get_source_id()
    {
        return $this->source_id;
    }

    protected function get_plugin_id()
    {
        return md5('woocommerce' + home_url());
    }

    public function set_token($token) {
        delete_option(self::OPTION_SOURCE);
        update_option(self::OPTION_TOKEN, $token);
        $this->token = $token;
    }

    public function get_token() {
        return $this->token;
    }

    public function update_product($id) {
        $post = get_post($id);

        $this->query_products([$post]);
    }

    public function update_products()
    {
        if (!$this->token) {
            return;
        }

        global $wpdb;

        $querystr = "
            SELECT pt.*, mt_exists.*
            FROM $wpdb->posts pt
            LEFT JOIN $wpdb->postmeta mt_exists
            ON pt.ID = mt_exists.post_id
            AND mt_exists.meta_key = '".feedcomet_product::META_LAST_UPDATE."'
            WHERE pt.post_type = 'product'
            AND mt_exists.post_id IS NULL
            OR mt_exists.meta_value < UNIX_TIMESTAMP(pt.post_modified)
        ";

        $posts = $wpdb->get_results($querystr, OBJECT);

        $this->query_products($posts);
    }

    private function query_products($posts) {
        if($posts) {
            $products = [];
            $products_json_stream = '';

            foreach ($posts as $post) {
                $product = new feedcomet_product($post);
                $products[] = $product;
                $products_json_stream .= $product->get_json();
            }

            $response = wp_remote_post(
                self::API_ADD_PRODUCTS_URL . $this->source_id . '/',
                array(
                    'method' => 'POST',
                    'headers' => array('PluginToken' => $this->token),
                    'body' => $products_json_stream,

                )
            );

            $saved_ids = array_map(
                function ($id) { return intval($id); },
                explode("\n", $response['body'])
            );

            foreach($products as $product) {
                if(in_array($product->get_id(), $saved_ids)) {
                    $product->set_last_updated();
                }
            }
        }
    }
}