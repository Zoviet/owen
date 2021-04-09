<?php 
require_once __DIR__.'/owen/Connect.php';
require_once __DIR__.'/owen/Modbus.php';
require_once __DIR__.'/owen/Config.php';
require 'vendor/autoload.php';

	//\owen\Config::load_defaults(); //сброс на настройки по умолчанию
	$connect =  new \owen\Connect();		
	$addrs = \owen\Config::connect_addrs();
	try {
		foreach ($addrs as $key=>$addr) {
			echo '<br/>	------------------'.$key.'-------------------<br/>';
			$connect->test($addr);			
			var_dump($connect::$messages);			
			echo implode('<br/>',array_slice($connect::$messages,0,5));
			echo '<br/>'.array_pop($connect::$messages).'<br/>';
			if (!empty($connect::$errors)) echo '<span class="is-danger"> Ошибки: <br/>'.implode('<br/> ',$connect::$errors).'</span>';
			sleep(0);
		}
	} catch (\Exception $e) { //если возникли ошибки, выводим их 
			echo $e->getMessage();
	}	
	$connect->close();
	echo '-------------------------------------------<br/>';
	echo nl2br($connect->modbus->status);

 ?>
