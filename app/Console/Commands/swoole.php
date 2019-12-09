<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Http\Models\AccountFake;


class Swoole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swoole {action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Swoole来了';


    const SPECIAL_SID = 11;
    const WIN_NUM = 3;
    const FAIL_NUM = 1;
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
        $arg = $this->argument("action");
        switch ($arg){
            case 'start':
                $this->info('swoole observer started');
                $this->start();
                break;
            case 'stop':
                $this->info('stoped');
                $this->stop();
                break;
            case 'restart':
                $this->info('restarted');
                break;
        }
    }

    public function start()
    {
        $this->info('swoole observer Ok');
        $redis = Redis::connection('web_game');
        $arr = [];
        //redis 初始化
        //$redis->flushall();
        $server = new \swoole_websocket_server("0.0.0.0",2555);
        $server->set(array(
            'worker_num' => 2, //设置启动的Worker进程数量
            'daemonize' =>true,  //是否后台守护进程
            //'daemonize' =>false,  //是否后台守护进程
            'max_request'=>10000,
            'dispatch_mode'=>2, //worker进程数据包分配模式  //1平均分配，2按FD取模固定分配，3抢占式分配，默认为取模(dispatch=2)
            'debug_mode'=> 0, //用于debug版本关闭日志输出
            'max_conn' => 10000, //此参数用来设置Server最大允许维持多少个tcp连接。超过此数量后，新进入的连接将被拒绝
            'log_file' => '/var/log/swoole/log.txt', //指定swoole错误日志文件。在swoole运行期发生的异常信息会记录到这个文件中。默认会打印到屏幕。
            'heartbeat_check_interval' => 60, //每隔多少秒检测一次，单位秒，Swoole会轮询所有TCP连接，将超过心跳时间的连接关闭掉
            'heartbeat_idle_time' => 600 //TCP连接的最大闲置时间，单位s , 如果某fd最后一次发包距离现在的时间超过heartbeat_idle_time会把这个连接关闭。(heartbeat_idle_time必须大于或等于heartbeat_check_interval
        ));

        //webSocket客户端与服务器建立连接并完成握手后会回调此函数
        $server->on('open', function (\swoole_websocket_server $server, $request) use ($redis,$arr) {
            //在线人数
            $redis->incr('online');
            //将用户fid　放入队列
            $redis->rpush('list',$request->fd);

            //匹配完成出队列
            if($redis->Llen('list')>=2){
                //出队列
                $arr[] = $redis->lpop('list');
                $arr[] = $redis->lpop('list');
                //房间号加1
                $room = $redis->incr('room');
                //echo  json_encode([$arr[0],$arr[1]]);
                //建立房间 存储对应关系 存储着fd
                $redis->set('room'.$room,json_encode([$arr[0],$arr[1]]));
                $redis->expire('room'.$room, 2*60);
                //初始化分数
                $redis->set('grade'.$room.':0',0);
                $redis->set('grade'.$room.':1',0);

                if ($server->isEstablished($arr[0]))  $server->push($arr[0],json_encode(['code'=>1,'msg'=>'匹配成功！','data'=>['room'=>$room,'troops'=>0]]));
                if ($server->isEstablished($arr[1]))  $server->push($arr[1],json_encode(['code'=>1,'msg'=>'匹配成功！','data'=>['room'=>$room,'troops'=>1]]));
            }
        });
        //当服务器收到来自客户端的数据帧时会回调此函数
        $server->on('message', function(\swoole_websocket_server $server, $frame) use($redis) {
            $tmp= explode(',',$frame->data);
            $code   = $tmp[0];
            $room   = $tmp[1];
            $troops = $tmp[3];
            $set = array_flip($server->connection_list());
            //获取用户个人信息 //eg： 2,room,account_id,troops
            switch ($code) {
                //获取用户信息
                case 2:
                    //$userInfo = json_decode(CacheController::getPlayerInfo($tmp[2]),true);
                    $userInfo = AccountFake::getRandAccountInfo();
                    //获取设备id
                    $tm = json_decode($redis->get('room'.$room),true);
                    $userInfo['troops'] = $troops;
                    $userInfo['room'] = $room;
                    echo "matching success  room:".$userInfo['room'].'--uid:'.$userInfo['uid'].'--'.date('Y-m-d H:i:s',time())."\r\n";
                    if(array_key_exists($tm[0],$set)) {
                        if ($server->isEstablished($tm[0])) $server->push($tm[0],json_encode(['code'=>2,'msg'=>'用户信息','data'=>$userInfo]));
                    }
                    if(array_key_exists($tm[1],$set)) {
                        if ($server->isEstablished($tm[1])) $server->push($tm[1],json_encode(['code'=>2,'msg'=>'用户信息','data'=>$userInfo]));
                    }
                    break;
                    //攻击怪物 eg:3,room,grade,troops
                case 3:
                    $tm = json_decode($redis->get('room'.$room),true);
                    $redis->set('grade'.$room.':'.$troops,$tmp[2]);
                    if(array_key_exists($tm[0],$set)) {
                       if ($server->isEstablished($tm[0]))  $server->push($tm[0],json_encode(['code'=>3,'data'=>['grade'=>$tmp[2],'troops'=>$troops]]));
                    }
                    if(array_key_exists($tm[1],$set)) {
                        if ($server->isEstablished($tm[1])) $server->push($tm[1],json_encode(['code'=>3,'data'=>['grade'=>$tmp[2],'troops'=>$troops]]));
                    }
                    break;
                    //获取分数  eg：4,room,account_id,troops;
                case 4:
                    //echo '****' . $redis->get('grade' . $tmp[1] . ':0') . '=======' . $redis->get('grade' . $tmp[1] . ':1') . "\r\n";
                    //$server_id = isset(json_decode(CacheController::getPlayerInfo($tmp[2]),true)['server'])
                    //    ? json_decode(CacheController::getPlayerInfo($tmp[2]),true)['server'] : 1 ;
                    if ($redis->get('grade' . $room . ':0') >= $redis->get('grade' . $room . ':1')) {
                        //发送奖励
                        //if(intval($tmp[3]) == 0) $this->sendAward($tmp[1],$tmp[2],self::WIN_NUM, $server_id);
                        //if($tmp[3]== 1) $this->sendAward($tmp[1],$tmp[2],self::FAIL_NUM, $server_id);
                        if ($server->isEstablished($frame->fd)) $server->push($frame->fd, json_encode(['code' => 4, 'data' => ['troops' => 0]]));
                    } else {
                        //if(intval($tmp[3]) == 1) $this->sendAward($tmp[1],$tmp[2],self::WIN_NUM, $server_id);
                        //if($tmp[3] == 0) $this->sendAward($tmp[1],$tmp[2],self::FAIL_NUM, $server_id);
                        if ($server->isEstablished($frame->fd)) $server->push($frame->fd, json_encode(['code' => 4, 'data' => ['troops' => 1]]));
                    }
                    break;
                default:
                    break;

            }
        });

        //关闭链接会触发该函数
        $server->on('close', function($server, $fd) use($redis) {
            //用户关闭页面  提出队列 （取消）
            $redis->lrem('list',0,$fd);
            //在线人数-1
            $redis->decr('online');
            echo "connection close client:".$fd.'--'.date('Y-m-d H:i:s',time())."\r\n";
        });

        //启动
        $server->start();
    }

    public function stop()
    {
        $this->info('swoole observer No');
    }

    /**
     * 发送奖励
     * @param $room
     * @param $uid
     * @param $num
     * @param $server
     */
    public function sendAward($room,$uid,$num,$server){
        //结果记录
        $id = DB::connection('active')->table('game_result')
            ->insertGetId(['room'=>$room,'uid'=>$uid,'create_time'=>date('Y-m-d H:i:s',time()),'num'=>$num]);
        $sid = self::SPECIAL_SID;
        //发送奖励
        $specialSql = "INSERT IGNORE INTO d_special_prop VALUES(NULL,$uid,$sid,$num,$num,$server)ON DUPLICATE KEY UPDATE count = count+$num,num = num+$num";
        $result=DB::connection('php')->insert($specialSql);
        if($result){
            DB::connection('active')->table('game_result')->where('id','=',$id)->update(['status'=>1]);
        }else{
            DB::connection('active')->table('game_result')->where('id','=',$id)->update(['status'=>-1]);
        }
    }
}
