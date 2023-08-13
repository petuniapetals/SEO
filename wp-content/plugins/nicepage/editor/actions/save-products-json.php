<?php
defined('ABSPATH') or die;

class NpSaveProductsJsonAction extends NpAction {

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
        $request = array();
        switch ($saveType) {
        case 'base64':
            $request = array_merge($_REQUEST, json_decode(base64_decode($_REQUEST['data']), true));
            break;
        case 'chunks':
            $chunk = new NpChunk();
            $ret = $chunk->save(NpSavePageAction::getChunkInfo($_REQUEST));
            if (is_array($ret)) {
                return NpSavePageAction::response(array($ret));
            }
            if ($chunk->last()) {
                $result = $chunk->complete();
                if ($result['status'] === 'done') {
                    $request = array_merge($_REQUEST, json_decode(base64_decode($result['data']), true));
                } else {
                    $result['result'] = 'error';
                    return NpSavePageAction::response(array($result));
                }
            } else {
                return NpSavePageAction::response('processed');
            }
            break;
        default:
            $request = stripslashes_deep($_REQUEST);
        }

        if (!isset($request['products'])) {
            return array(
                'status' => 'error',
                'type' => 'CmsSaveServerError',
                'message' => 'incorrect data for save',
            );
        }

        $productsJson = isset($request) ? $request : null;
        np_data_provider()->saveProductsJson($productsJson);
        return array(
            'result' => 'done',
            'data' => $productsJson,
        );
    }
}
NpAction::add('np_save_products_json', 'NpSaveProductsJsonAction');