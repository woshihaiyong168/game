<?php

namespace App\Http\Models;

use Illuminate\Database\Eloquent\Model;

class AccountFake extends Model
{
    protected $table = 'account_fake';
    public $timestamps = false;
    //protected $connection='active';

    /**
     *
     * 获取用户信息
     *
     * @param $account_id
     * @return mixed
     */
    public static function getAccountInfo($account_id)
    {
       $account_info =  self::where(['id' => $account_id])->first();

       return $account_info;
    }

    /**
     *
     * 获取随机用户信息
     *
     * @return mixed
     */
    public static function getRandAccountInfo()
    {
        $id = mt_rand(1,1000);
        $account_info = AccountFake::select('id as uid','nick_name as name','avatar','sex')
                                    //->OrderByRaw('RAND()')
                                    ->where(['id' => $id])
                                    ->limit(1)
                                    ->first();
        return $account_info;
    }
}
