<?php
class B24
{
    protected ?string $whook;

    public function __construct(string $hook) {
        $this->whook = $hook;
    }

    public function api_request(string $method, array $args) {
        if (empty($this->whook) )
            throw new Exception('WEBHOOK_VERIFICATION_ERROR'.':'.'Webhook does not exist!');

        $url = $this->whook.$method;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args, JSON_FORCE_OBJECT));

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['error'])) 
            throw new Exception($response['error'].':'.$response['error_description']);

        if ($method == 'batch' && count($response['result']['result_error']) > 0) {
            //var_export($response);
            $result_error = reset($response['result']['result_error']);
            //var_export($result_error); die;
            throw new Exception($result_error['error'].':'.$result_error['error_description']);
        }
            
        return $response;
    }

    public function get_all(string $method, array $args, array $alter, bool $batch=true) {
        $result = [];
        $stop = false;
        $params = $args;

        while (!$stop) {
            $dataset = [];
            $r = $this->api_request($method, $params);
            if (isset($r['next'])) {
                $params['start'] = $r['next'];
            } else {
                $stop = true;
            }

            if (isset($r['result'])) {
                $items = $r['result'];
                if (isset($alter['items']) && isset($r['result'][$alter['items']])) {
                    $items = $r['result'][$alter['items']];
                }

                foreach ($items as $key => $item) {
                    $current_data = $item;
                    if (isset($alter['key'])) {
                        if (!is_array($item) || !array_key_exists($alter['key'], $item)) {
                            throw new Exception('DATASET_KEY_ERROR'.':'.'Dataset key does not exist!');
                        }
                        $current_data = $item[$alter['key']];
                    }
                    if (!empty($alter['savekey'])) {
                        $dataset[$key] = $current_data;
                    } else {
                        $dataset[] = $current_data;
                    }
                }

                if ($batch) {
                    $result[] = $dataset;
                } else {
                    $result = array_merge($result, $dataset);
                }
            }
        }

        return $result;
    }
}

$api_hook = '';
$result = array('script_name' => $_SERVER['SCRIPT_NAME'], 'run_datetime' => date('d.m.Y'));
try 
    {
        $b24 = new B24($api_hook);
        
        // count all deals
        $r = $b24->api_request('crm.deal.list', ['select' => ['ID']]);
        $result['count_deals_total'] = isset($r['total']) ? $r['total'] : 0;
        // count all contacts
        $r = $b24->api_request('crm.contact.list', ['select' => ['ID']]);
        $result['count_contacts_total'] = isset($r['total']) ? $r['total'] : 0;

        // count contacts with comments
        $r = $b24->api_request('crm.contact.list', ['select' => ['ID', 'COMMENTS'], 'filter' => ['!=COMMENTS' => '']]);
        $result['count_contacts_with_comments'] = isset($r['total']) ? $r['total'] : 0;

        // search deals without contacts
        $r = $b24->get_all('crm.item.list', ['entityTypeId' => 2, 'select' => ['id', 'contactIds']], ['items' => 'items'], false);
        $deals_without_contacts = [];
        foreach ($r as $deal) {
            if (count($deal['contactIds']) == 0) {
                $deals_without_contacts[] = $deal['id'];
            }
        }
        $result['deals_without_contacts'] = implode(',', $deals_without_contacts);

        // count deals by category
        $category_key_name = 'count_deals_in_category_';
        $r = $b24->get_all('crm.category.list', ['entityTypeId' => 2], ['items' => 'categories', 'key' => 'id']);
        foreach ($r as $batch) {
            $cmd_arr = [];
            foreach ($batch as $category_id) {
                $current_obj = ['select' => ['ID'], 'filter' => ['CATEGORY_ID' => $category_id]];
                $cmd_arr[$category_id] = 'crm.deal.list?'.http_build_query($current_obj);
            }

            $batch_result = $b24->api_request('batch', ['halt' => 1, 'cmd' => $cmd_arr]);

            if (isset($batch_result['result']['result_total'])) {
                foreach ($batch_result['result']['result_total'] as $category_id => $deals_total) {
                    $current_key_name = $category_key_name.$category_id;
                    $result[$current_key_name] = $deals_total;
                }
            }
        }

        // user field in smart process
        $smart_entity = 1038;
        $search_title = 'Баллы';
        $user_fieldname = '' ;
        $result['points_sum'] = 0;
        
        $r = $b24->get_all('crm.item.fields', ['entityTypeId' => $smart_entity], ['items' => 'fields', 'savekey' => 1], false);
        $filtered = array_filter($r, function($v) use ($search_title) { return $v['title'] == $search_title; });
        $user_fieldname = array_key_first($filtered);
        if (empty($user_fieldname)) 
            throw new Exception('USER_FIELD_ERROR'.':'.'Field <<'.$search_title.'>> not found in smart process (dynamic id='.$smart_entity.')!');

        $r = $b24->get_all('crm.item.list', ['entityTypeId' => $smart_entity, 'select' => [$user_fieldname], 'filter' => ['!='.$user_fieldname => '']], ['items' => 'items'], false);
        foreach ($r as $item) {
            $result['points_sum'] += (double)$item[$user_fieldname];
        }
    }
catch (Exception $e) 
    {
        if (!isset($result['error'])) {
            list($err, $err_desc) = explode(':', $e->getMessage());
            $result['error'] = $err;
            $result['error_description'] = $err_desc;
        }
    }
finally
    {
        print_r($result);
    }
?>