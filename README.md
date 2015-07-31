# tencentyun-video-php
php sdk for [腾讯云微视频](http://www.qcloud.com/wiki/%E5%BE%AE%E8%A7%86%E9%A2%91%E6%9C%8D%E5%8A%A1%E6%A6%82%E8%BF%B0)

## 安装（直接下载源码集成）

### 直接下载源码集成
从github下载源码装入到您的程序中，并加载include.php
调用请参考示例1

## 修改配置
修改Qcloud_video/Conf.php内的appid等信息为您的配置

## 上传、查询、删除程序示例1（使用tencentyun提供的include.php）
```php
<?php

require('./include.php');

use Qcloud_video\Auth;
use Qcloud_video\Video;

$bucketName = 'abcde';


$srcPath = './test.mp4';
$dstPath = "/test.mp4";
$dstPath_slice = "/test_slice.mp4";
$remoteFolder = "/test/";

//设置超时时间，单位秒
Video::setTimeout(10);

//创建目录
$createFolderRet = Video::createFolder($bucketName, $remoteFolder);
var_du
//分片上传
$sliceUploadRet = Video::upload_slice($srcPath, $bucketName, $dstPath_slice);
//用户指定分片大小来分片上传
//$sliceUploadRet = Video::upload_slice($srcPath, $bucketName, $dstPath_slice, null, 3*1024*1024);
//指定了session，可以实现断点续传
//$sliceUploadRet = Video::upload_slice($srcPath, $bucketName, $dstPath_slice, null, 2000000, '48d44422-3188-4c6c-b122-6f780742f125+CpzDLtEHAA==');
var_dump($sliceUploadRet);


//listFolder
$listRet = Video::listFolder($bucketName, "/");
var_dump($listRet);

//prefixSearch
$ret = Video::prefixSearch($bucketName, "/test");
var_dump($ret);

//updateFolder
$updateRet = Video::updateFolder($bucketName, $remoteFolder, '{json:0}');
var_dump($updateRet);

//update
$updateRet = Video::update($bucketName, $dstPath, '{json:1}');
var_dump($updateRet);

//statFolder
$statRet = Video::statFolder($bucketName, $remoteFolder);
var_dump($statRet);

//stat
$statRet = Video::stat($bucketName, $dstPath);
var_dump($statRet);

//delFolder
$delRet = Video::delFolder($bucketName, $remoteFolder);
var_dump($delRet);

//del
$delRet = Video::del($bucketName, $dstPath);
var_dump($delRet);
$delRet = Video::del($bucketName, $remoteFolder);
var_dump($delRet);
$delRet = Video::del($bucketName, $dstPath_slice);
var_dump($delRet)



```
