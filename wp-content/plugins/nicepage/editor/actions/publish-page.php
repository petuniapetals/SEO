<?php
defined('ABSPATH') or die;

class NpPublishPageAction extends NpAction
{

    /**
     * Process action entrypoint
     *
     * @return array
     *
     * @throws Exception
     */
    public static function process()
    {
        include_once dirname(__FILE__) . '/chunk.php';

        $saveType = isset($_REQUEST['saveType']) ? $_REQUEST['saveType'] : '';
        switch ($saveType) {
        case 'base64':
            $_REQUEST = array_merge($_REQUEST, json_decode(base64_decode($_REQUEST['data']), true));
            break;
        case 'chunks':
            $chunk = new NpChunk();
            $ret = $chunk->save(self::getChunkInfo($_REQUEST));
            if (is_array($ret)) {
                return self::response(array($ret));
            }
            if ($chunk->last()) {
                $result = $chunk->complete();
                if ($result['status'] === 'done') {
                    $_REQUEST = array_merge($_REQUEST, json_decode(base64_decode($result['data']), true));
                } else {
                    $result['result'] = 'error';
                    return self::response(array($result));
                }
            } else {
                return self::response('processed');
            }
            break;
        default:
        }

        if (!isset($_REQUEST['id'])) {
            return array(
                'status' => 'error',
                'type' => 'CmsSaveServerError',
                'message' => 'post parameter ID missing',
            );
        }

        $post_id = $_REQUEST['id'];

        if ($post_id <= 0) {
            $insert_data = array();

            $insert_data['post_type'] = 'page';
            $insert_data['post_status'] = 'publish';

            $post_id = wp_insert_post($insert_data);
            if (is_wp_error($post_id)) {
                //TODO: process error
            }
        }

        $post = get_post($post_id);

        if (!$post) {
            return array(
                'result' => 'error',
                'type' => 'CmsSaveServerError',
                'message' => 'Page not found'
            );
        }

        wp_update_post(
            array(
                'ID' => $post_id,
                'post_status' => 'publish',
            )
        );

        return array(
            'result' => 'done',
            'data' => $post_id,
        );
    }
}

NpAction::add('np_publish_page', 'NpPublishPageAction');