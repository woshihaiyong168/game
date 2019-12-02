<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Models\AccountFake;
use Illuminate\Support\Facades\Redis;

class test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {


       //$redis =  Redis::connection('web_game');
       //$redis->set('test', 'hello');
       //$t = $redis->get('test');
       //var_dump($t);exit;
       $account_info = AccountFake::getRandAccountInfo();
        var_dump($account_info);
    }
}
