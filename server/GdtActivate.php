<?php
    
    // 广点通回调服务
    class GdtActivate
    {       
        private $server = null;
        private $logpath = null;
        public function __construct()
        {
            date_default_timezone_set('Asia/Shanghai');
            error_reporting(E_ALL ^ E_NOTICE);
            $this->setpath(); // 设置日志目录
            $this->setcode(); // 返回代码
            $this->init(); // 初始化
        }

        // 实例化
        public function init()
        {
            $this->server = new swoole_server("127.0.0.1", 9503);

            // 服务参数配置
            $this->server->set([
                'task_worker_num' => 8,  // task进程的数量
                'task_ipc_mode' => 1,
                'log_file' => $this->logpath . 'server/gdt_activate.log',
            ]);

            $this->server->on('Start', [$this, 'onStart']); // 启动事件
            $this->server->on('WorkerStart', [$this, 'onWorkerStart']); // 启动进程事件
            $this->server->on('Receive', [$this, 'onReceive']); // 接收
            $this->server->on('Task', [$this, 'onTask']); // 任务
            $this->server->on('Finish', [$this, 'onFinish']); // 结束
            $this->server->start(); // 启动
        }

        // 启动事件
        public function onStart($server)
        {
            cli_set_process_title("active_gdt"); // 设置进程名,以便热更新
        }

        // 启用数据库连接池
        public function onWorkerStart($server,$worker_id)
        {
            // if ( $worker_id >= $this->server->setting['worker_num'] ) 
            // {
            //     $this->db = new mysqli;
            //     $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');
            // }
        }

        // 接收数据
        public function onReceive($serv, $fd, $from_id, $receive)
        {
            $data = json_decode($receive, true);
            $this->server->task($data); // 异步任务
            $this->server->close($fd); // 关闭当前服务
        }

        // 任务计划
        public function onTask($server,$task_id,$from_id,$data) 
        {
            $clickinfo = $data['clickinfo'];
            $muid = $clickinfo['muid'];
            $adv_id = $clickinfo['adv_id'];
            $app_type = $clickinfo['app_type'];
            $click_timestamp = $clickinfo['create_timestamp'];
            unset($data['clickinfo']);

            $app_id = $data['app_id'];
            $idfa = $data['idfa'];
            $mac = $data['mac'];
            $device_id = $data['device_id'];
            $device_os = $data['device_os'];
            $device_model = $data['device_model'];
            $ip = $data['ip'];
            $sdk_version = $data['sdk_version'];
            $coop_id = $data['coop_id'];
            $activate_timestamp = $data['activate_timestamp'] ? floor($data['activate_timestamp'] / 1000) : time();
            $create_timestamp = time();

            $this->db = new mysqli;
            $this->db->connect('dbip', 'user', 'dbpasswd', 'dbname');
            
            // 获取AppStoreID
            $appstore_id = $clickinfo['appstore_id'];
            if ( ! $appstore_id) 
            {
            	$log = '203' . ' - ' . json_encode($data); // appstore_id不存在
                $this->setlog($log, 2);
                return false;
            }

            // 获取广点通账号ID
            $adv_id = $clickinfo['adv_id'];
            if ( ! $adv_id)
            {
            	$log = '604' . ' - ' . json_encode($data); // adv_id不存在
                $this->setlog($log, 2);
                return false;
            }

            // 获取游戏app_uniq_id
            $sql = "select app_uniq_id from gas_app where app_id = '{$app_id}' and status = 1";
            $app = $this->db->query($sql)->fetch_assoc(); 
            $app_uniq_id = $app['app_uniq_id']; // app_id原始唯一值
            if ( ! $app_uniq_id)
            {
                $log = '202' . ' - ' . json_encode($data); // app_uniq_id不为空
                $this->setlog($log, 2);
                return false;
            }

            // 获取游戏信息
            $sql = "select * from gdt_app where app_id = '{$app_id}' and adv_id = '{$adv_id}' and appstore_id = '{$appstore_id}' and status = 1";
            $appinfo = $this->db->query($sql)->fetch_assoc(); 
            if (empty($appinfo))
            {
                $log = '204' . ' - ' . json_encode($data); // appstore_id不存在
                $this->setlog($log, 2);
                return false;
            }

            // 判断idfa是否激活过
            $activatetb = 'gdt_activate_' . $app_uniq_id; // 积分墙激活表
            $sql = "select activate_id from `{$activatetb}` where idfa = '{$idfa}'";
            $isActivated = $this->db->query($sql)->num_rows;
            if ($isActivated)
            {
                $log = '403' . ' - ' . json_encode($data); // 已激活过
                $this->setlog($log, 2);
                return false;
            }

            // 写入激活表
            $sql = "insert into `{$activatetb}`
                        (`app_id`,`appstore_id`,`coop_id`,`adv_id`,`app_type`,`muid`,`idfa`,`mac`,`ip`,`activate_timestamp`,`device_os`,`device_model`,`sdk_version`,`create_timestamp`,`update_timestamp`,`status`)
                    values
                        ('{$app_id}','{$appstore_id}','{$coop_id}','{$adv_id}','{$app_type}','{$muid}','{$idfa}','{$mac}','{$ip}','{$activate_timestamp}','{$device_os}','{$device_model}','{$sdk_version}','{$create_timestamp}','', 0)";
            $this->db->query($sql);
            $activate_id = mysqli_insert_id($this->db);

            if ( ! $activate_id)
            {
            	$log = '503' . ' - ' . json_encode($data); // 插入激活表失败
                $this->setlog($log, 2);
                return false;
            }

            $log = $app_id . '\x02' . $appstore_id . '\x02' . $coop_id . '\x02' . $idfa . '\x02' . $mac . '\x02' . $muid  . '\x02' . $adv_id . '\x02' . $device_os . '\x02' . $device_model . '\x02' . $sdk_version . '\x02' . $ip; // 插入激活表成功
            $this->setlog($log, 3);

            // 第四步：获取激活回调的加密密钥和签名密钥
			$sign_key = $appinfo['sign_key'];
			$encrypt_key = $appinfo['encrypt_key'];
			if (! $sign_key || ! $encrypt_key)
			{
				$log = '604' . ' - ' . json_encode($data); // 插入密钥不合法
                $this->setlog($log, 2);
                return false;
			}

			// 组装回调通知信息
			$query_string = 'muid=' . urlencode($clickinfo['muid']) . '&conv_time=' . urlencode($activate_timestamp) ;
			$page = "http://t.gdt.qq.com/conv/app/{$appstore_id}/conv?" . $query_string;
			$property = $sign_key . '&GET&' . urlencode($page);
			$signature = md5($property);
			$base_data = $query_string . '&sign=' . urlencode($signature);
			$data = urlencode(base64_encode($this->SimpleXor($base_data, $encrypt_key)));
			$attachment = 'conv_type=' . urlencode('MOBILEAPP_ACTIVITE') . '&app_type=' . urlencode(strtoupper($clickinfo['app_type'])) . '&advertiser_id=' . urlencode($clickinfo['adv_id']);
			$url = "http://t.gdt.qq.com/conv/app/{$appstore_id}/conv?v={$data}&{$attachment}";
			

			$ctx = stream_context_create(array('http' => array('timeout' => 5)));// 设置一个超时时间，单位为秒
            $callback = file_get_contents($url, 0, $ctx);
            unset($ctx);

            // 记录返回日志
            $log = $page . ' - ' . $callback;
            $this->setlog($log, 4);

			$callback = json_decode($callback, true);

            $update_timestamp = time();
			if ($callback['ret'] == 0) // 回调成功
			{
				$update['status'] = 1;
				$sql = "update 
                                `{$activatetb}` 
                            set
                                `status` = 1,`update_timestamp`='{$update_timestamp}'
                            where 
                                `muid` = '{$muid}'";
                $ret = $this->db->query($sql);
			} else { // 回调失败
				$interval = $activate_timestamp - $click_timestamp;
				if ($interval >= 7*24*60*60) // 7天后激活
				{
					$sql = "update 
                                `{$activatetb}` 
                            set
                                `status` = 2,`update_timestamp`='{$update_timestamp}'
                            where 
                                `muid` = '{$muid}'";
               		$this->db->query($sql);
				}
			}
        }

        
        // 任务结束处理事件
        public function onFinish($server,$task_id, $data)
        {
            // ...
        }


        // 日志目录
        public function setpath()
        {
            $this->logpath = dirname(dirname(__FILE__)).'/logs/';
        }

        // 写入日志
        public function setlog($log,$type)
        {
            if (is_array($log))
            {
                $log = json_encode($log);
            }
            
            if ($type == 2) { // 失败日志
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/gdt_fail/' . date('Y-m-d') . '.log';
            } else if ($type == 3) { // 成功日志
                $log = date('Y-m-d H:i:s') . '\x02' . $log . "\n";
                $file  = $this->logpath . 'activate/gdt/' . date('Y-m-d') . '.log';
            } else if ($type == 4) { // 渠道返回状态日志
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/gdt_callback/qd_' . date('Y-m-d') . '.log';
            } else if ($type == 5) { // CP返回状态日志
                $log = date('Y-m-d H:i:s') . ' - ' . $log . "\n";
                $file  = $this->logpath . 'activate/gdt_callback/cp_' . date('Y-m-d') . '.log';
            } else {
                return false;
            }

            file_put_contents($file, $log, FILE_APPEND);
            return true;
        }

        // 统一返回值
        public function setcode()
        {
            $this->code = array(
                                    0 => '成功',

                                    101 => '失败',
                                    102 => '密钥不正确',
                                    103 => '连接服务器失败',

                                    201 => 'app_id为空',
                                    202 => 'app_uniq_id不存在',
                                    203 => 'appstore_id为空',
                                    204 => 'appstore_id不合法',

                                    301 => '渠道名为空',
                                    302 => '渠道ID不正确',
                                    303 => '渠道不存在',

                                    401 => 'idfa为空',
                                    402 => 'idfa没有点击',
                                    403 => 'idfa已经激活过',
                                    404 => 'muid为空',

                                    501 => '更新成功',
                                    502 => '更新失败',
                                    503 => '插入失败',
                                    504 => '不通过时间阀值插入成功',
                                    505 => '不通过时间阀值插入失败',
                                    506 => '不通过IP阀值插入成功',
                                    507 => '不通过IP阀值插入失败',
                                    508 => '通知渠道更新激活表失败',
                                    509 => '通知CP更新激活表失败',
                                    
                                    601 => 'app类型不合法',
                                    602 => '账户不能为空',
                                    603 => 'click_id不能为空',
                                    604 => '密钥不合法',

                                );

        }

        // php异或函数
	  	public function SimpleXor($string, $key)
		{
			$retval = '';
			$j = 0;
			$lenstr = strlen($string);
			$lenkey = strlen($key);

			for ($i=0; $i < $lenstr; $i++) 
			{ 
				$retval .= chr(ord($string[$i]) ^ ord($key[$j]));
				$j++;
				$j = $j % $lenkey;
			}
			return $retval;
		}

    }


    $server = new GdtActivate();


/*    
    nohup /usr/local/php/bin/php /server/GdtActivate.php 1>/dev/null & 启动后台执行php，忽略标准输出
    ps -ef | grep /server/GdtActivate.php 查看进程
    kill -9 `ps -ef | grep /server/GdtActivate.php | awk '{print $2}'` 停止执行php脚本
*/


