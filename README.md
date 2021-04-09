# ================================
# Owen: класс работы с контроллерами OWEN по Modbus TCP
# ================================

Класс реализует взаимодействие с ПЛК ОВЕН по протоколу Modbus TCP с учетом рекомендаций производителя по обмену данными. 

```
composer require zoviet/owen

```

## Функционал: 

1. Поддержка списка адресов
2. Поддержка единого интерфейса для чтения и записи в регистры
3. Поддержка чтения массивов
4. Поддержка прямого и обратного порядка бит
5. Запись и чтение Coils через байтовые слова


## Настройки соединения по умолчанию

```php
			'IP'=>'127.0.0.1',  //IP-адрес PLC
			'Port'=>502, //Порт
			'UnitID'=>1, //ID устройства
			'Endianess'=>'LOW_ENDIAN', //Порядок байт по умолчанию: обратный порядок: 'BIG_ENDIAN'
```


### Использование


 
```php

// Изменение настроек соединения по умолчанию

\owen\Config::set('connect', 'IP', '191.168.1.10');
\owen\Config::set('connect', 'Port', 555);

$owen = new \owen\Connect();

$temp_addr = [2,2,'ReadRegister','REAL','Температура','tempOut',''];

$temp = $owen->pull($temp_addr);

$speed_addr = [11,1,'WriteRegister','WORD','Установка скорости вращения (от 0 до 1000)','speedin','15'];

$speed = $owen->push($speed_addr);

$data_addr = [15,80,'ReadArray','WORD','Массив данных','DataArray',''];

$data = $owen->push($data_addr); //массив данных с ПЛК

// с использованием конфига

$connect =  \owen\Config::get('connect'); 

\owen\Config::set('connect','temp',[2,2,'ReadRegister','REAL','Температура','tempOut','']);

\owen\Config::set('connect','speed',[11,1,'WriteRegister','WORD','Установка скорости вращения (от 0 до 1000)','speedin','15']);

$temp = $owen->pull('temp');

$speed = $owen->push('speed');


```


