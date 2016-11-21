<?php

namespace SchulzeFelix\Stat\Api;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;

class StatSubAccounts extends BaseStat
{

    /**
     * @return Collection
     */
    public function list() : Collection
    {
        $response = $this->performQuery('subaccounts/list');

        $subaccounts = collect();

        if( ! isset($response['User']) ){
            return $subaccounts;
        }

        if(isset($response['User']['Id'])) {
            $subaccounts->push($response['User']);
        } else {
            $subaccounts = collect($response['User']);
        }

        $subaccounts->transform(function ($item, $key) {

            return [
                'id' => (int)$item['Id'],
                'login' => $item['Login'],
                'api_key' => $item['ApiKey'],
                'created_at' => Carbon::parse($item['CreatedAt']),
            ];
        });

        return $subaccounts;
    }

}