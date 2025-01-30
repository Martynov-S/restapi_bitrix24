<?php
declare(strict_types=1);

use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Bitrix24\SDK\Core\Exceptions\BaseException;

require_once 'vendor/autoload.php';

$api_hook = '';

$b24 = ServiceBuilderFactory::createServiceBuilderFromWebhook($api_hook);

$result = array('script_name' => $_SERVER['SCRIPT_NAME'], 'run_datetime' => date('d.m.Y'));

try
    {
        // count all deals
        $result['count_deals_total'] = $b24->getCRMScope()->deal()->countByFilter([]);
        // count all contacts
        $result['count_contacts_total'] = $b24->getCRMScope()->contact()->countByFilter([]);

        // count contacts with comments
        $result['count_contacts_with_comments'] = $b24->getCRMScope()->contact()->countByFilter(['!=COMMENTS' => '']);

        // search deals without contacts
        $deals_without_contacts = [];
        $r = $b24->getCRMScope()->item()->batch->list(2, [], [], ['id', 'contactIds']);
        foreach ($r as $item) {
            if (count($item->contactIds) == 0) {
                $deals_without_contacts[] = $item->id;
            }
        }
        $result['deals_without_contacts'] = implode(',', $deals_without_contacts);

        // count deals by category
        $category_key_name = 'count_deals_in_category_';
        $is_final = false;
        $params = ['entityTypeId' => 2, 'start' => 0];
        while (!$is_final) {
            $r = $b24->core->call('crm.category.list', $params)->getResponseData();
            $params['start'] = $r->getPagination()->getNextItem();
            if (empty($params['start'])) 
                $is_final = true;
            
            foreach ($r->getResult()['categories'] as $category) {
                $current_key_name = $category_key_name.$category['id'];
                $result[$current_key_name] = $b24->getCRMScope()->deal()->countByFilter(['CATEGORY_ID' => $category['id']]);
            }
        }

        // user field in smart process
        $smart_entity = 1038;
        $search_title = 'Баллы';
        $user_fieldname = '' ;
        $result['points_sum'] = 0;
        $r = $b24->getCRMScope()->item()->fields($smart_entity)->getFieldsDescription()['fields'];
        $filtered = array_filter($r, function($v) use ($search_title) { return $v['title'] == $search_title; });
        $user_fieldname = array_key_first($filtered);
        if (empty($user_fieldname)) 
            throw new BaseException('Field <<'.$search_title.'>> not found in smart process (dynamic id='.$smart_entity.')!');

        $r = $b24->getCRMScope()->item()->batch->list($smart_entity, [], ['!='.$user_fieldname => ''], ['id', $user_fieldname]);
        foreach ($r as $item) {
            $result['points_sum'] += (double)$item->{$user_fieldname};
        }
    }
catch (Throwable $e) 
    {
        $result['error'] = "Error: ".$e->getMessage();
    }
finally
    {
        print_r($result);
    }
?>