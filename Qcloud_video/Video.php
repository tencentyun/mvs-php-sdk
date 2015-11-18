<?php

namespace Qcloud_video;

class Video
{
    // 60 seconds
    const EXPIRED_SECONDS = 60;

    //3M
    const DEFAULT_SLICE_SIZE = 3145728;

    //10M
    const MIN_SLICE_FILE_SIZE = 10485760;

    const MAX_RETRY_TIMES = 3;
    const VIDEO_FILE_NOT_EXISTS = -1;
    const VIDEO_NETWORK_ERROR = -2;
    const VIDEO_PARAMS_ERROR = -3;
    const VIDEO_ILLEGAL_SLICE_SIZE_ERROR = -4;
    private static $timeout = 30;
    
    public static function setTimeout($t) {
        if (!is_int($t) || $t < 0) {
            return false;
        }

        self::$timeout = $t;
        return true;
    }

    public static function videoUrlEncode($path) {
        //return str_replace('%2F', '/',  urlencode($path));
        return str_replace('%2F', '/',  rawurlencode($path));
    }
    public static function generateResUrl($bucketName, $dstPath) {
        return Conf::API_VIDEO_END_POINT . Conf::APPID . '/' . $bucketName . $dstPath;
    }
        
    public static function sendRequest($req) {
        //var_dump($req);

        $rsp = Http::send($req);
        $info = Http::info();
        $ret = json_decode($rsp, true);

        if ($ret) {
            if (0 === $ret['code']) {
                $ret['httpcode'] = $info['http_code'];
                return $ret;
            } else {
                return array(
                    'httpcode' => $info['http_code'], 
                    'code' => $ret['code'], 
                    'message' => $ret['message'], 
                    'data' => array()
                );
            }
        } else {
            return array(
                    'httpcode' => $info['http_code'], 
                    'code' => self::VIDEO_NETWORK_ERROR, 
                    'message' => 'network error', 'data' => array()
                );
        }
    }

    /**
     * 上传文件
     * @param  string  $srcPath      本地文件路径
     * @param  string  $bucketName   上传的bcuket名称
     * @param  string  $dstPath      上传的文件路径
     * @param  string  $videoCover   视频封面的url
	 * @param  string  $bizAttr      文件属性，业务端维护 
	 * @param  string  $title        视频标题
	 * @param  string  $desc         视频描述
	 * @param  string  $magicContext 自定义回调参数
     * @return [type]                [description]
     */
    public static function upload($srcPath, $bucketName, $dstPath, $videoCover = null, $bizAttr = null, $title = null, $desc = null, $magicContext = null) {

        $srcPath = realpath($srcPath);

        if (!file_exists($srcPath)) {
            return array(
                    'httpcode' => 0, 
                    'code' => self::VIDEO_FILE_NOT_EXISTS, 
                    'message' => 'file '.$srcPath.' not exists', 
                    'data' => array());
        }

        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $dstPath);
        $sign = Auth::appSign($expired, $bucketName);
        $sha1 = hash_file('sha1', $srcPath);

        $data = array(
            'op' => 'upload',
            'sha' => $sha1,
            'video_cover'  => (isset($videoCover) ? $videoCover : ''),
            'biz_attr'     => (isset($bizAttr) ? $bizAttr : ''),
			'video_title'  => (isset($title) ? $title : ''),
			'video_desc'   => (isset($desc) ? $desc : ''),
			'magicContext' => (isset($magicContext) ? $magicContext : ''),
        );

        if (function_exists('curl_file_create')) {
            $data['filecontent'] = curl_file_create($srcPath);
        } else {
            $data['filecontent'] = '@'.$srcPath;
        }
        
        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization:'.$sign,
            ),
        );

        return self::sendRequest($req);
    }

    /**
     * 上传文件
     * @param  string  $srcPath      本地文件路径
     * @param  string  $bucketName   上传的bcuket名称
     * @param  string  $dstPath      上传的文件路径
     * @param  string  $videoCover   视频封面url
	 * @param  string  $bizAttr      文件属性，业务端维护 
	 * @param  string  $title        视频标题
	 * @param  string  $desc         视频描述
	 * @param  string  $magicContext 自定义回调参数
	 * @param  int 	   $sliceSize	 上传分片大小，不设置则采用默认值
	 * @param  string  $session 	 如果是断点续传, 则带上(唯一标识此文件传输过程的id, 由后台下发, 调用方透传) 
     * @return [type]                [description]
     */
    public static function upload_slice(
            $srcPath, $bucketName, $dstPath, $videoCover = null,
            $bizAttr = null, $title = null, $desc = null, $magicContext = null,
            $sliceSize = self::DEFAULT_SLICE_SIZE, $session = null) {

        $fileSize = filesize(realpath($srcPath));
        if ($fileSize < self::MIN_SLICE_FILE_SIZE) {
            return self::upload(
                    $srcPath, $bucketName, $dstPath, $videoCover,
                    $bizAttr);
        }

        $srcPath = realpath($srcPath);
        $dstPath = self::videoUrlEncode($dstPath);
        if (!file_exists($srcPath)) {
            return array(
                    'httpcode' => 0, 
                    'code' => self::VIDEO_FILE_NOT_EXISTS, 
                    'message' => 'file '.$srcPath.' not exists', 
                    'data' => array());
        }

        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $dstPath);
        $sign = Auth::appSign($expired, $bucketName);
        $sha1 = hash_file('sha1', $srcPath);

        $ret = self::upload_prepare(
                $fileSize, $sha1, $sliceSize, 
                $sign, $url, $bizAttr, $videoCover, $title, $desc, $magicContext, $session);
        if($ret['httpcode'] != 200
                || $ret['code'] != 0) {
            return $ret;
        }

        if(isset($ret['data']) 
                && isset($ret['data']['url'])) {
        //秒传命中，直接返回了url
            return $ret;
        }

        $sliceSize = $ret['data']['slice_size'];
        if ($sliceSize > self::DEFAULT_SLICE_SIZE ||
            $sliceSize <= 0) {
            $ret['code'] = self::VIDEO_ILLEGAL_SLICE_SIZE_ERROR;
            $ret['message'] = 'illegal slice size';
            return $ret;
        }

        $session = $ret['data']['session'];
        $offset = $ret['data']['offset'];

        $ret = self::upload_data(
                $fileSize, $sha1, $sliceSize,
                $sign, $url, $srcPath,
                $offset, $session);
        return $ret;
    }

    private static function upload_prepare(
            $fileSize, $sha1, $sliceSize, 
            $sign, $url, $bizAttr, $videoCover = null, $title = null, $desc = null, $magicContext = null, $session = null) {
        $data = array(
            'op' => 'upload_slice',
            'filesize' => $fileSize,
            'sha' => $sha1,
            'video_cover'  => (isset($videoCover) ? $videoCover : ''),
            'video_title' => (isset($title) ? $title : ''),
            'video_desc' => (isset($desc) ? $desc : ''),
            'magicContext' => (isset($magicContext) ? $magicContext : ''),
        );
        isset($bizAttr) && 
            $data['biz_attr'] = $bizAttr;
        isset($session) &&
            $data['session'] = $session;

        if ($sliceSize > 0) {
            if ($sliceSize <= self::DEFAULT_SLICE_SIZE) {
                $data['slice_size'] = $sliceSize;
            } else {
                $data['slice_size'] = self::DEFAULT_SLICE_SIZE;
            }
        }

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization:'.$sign,
            ),
        );

        $ret = self::sendRequest($req);
        return $ret;
    
    }

    private static function upload_data(
            $fileSize, $sha1, $sliceSize,
            $sign, $url, $srcPath, 
            $offset, $session) {
    
        while ($fileSize > $offset) {
            $filecontent = file_get_contents(
                    $srcPath, false, null,
                    $offset, $sliceSize);

            if ($filecontent === false) {
                return $ret;
            }

            $boundary = '---------------------------' . substr(md5(mt_rand()), 0, 10); 
            $data = self::generateSliceBody(
                    $filecontent, $offset, $sha1,
                    $session, basename($srcPath), $boundary);

            $req = array(
                'url' => $url,
                'method' => 'post',
                'timeout' => self::$timeout,
                'data' => $data,
                'header' => array(
                    'Authorization:'.$sign,
                    'Content-Type: multipart/form-data; boundary=' . $boundary,
                ),
            );

            $retry_times = 0;
            do {
                $ret = self::sendRequest($req);
                var_dump($ret);
                if ($ret['httpcode'] == 200
                    && $ret['code'] == 0) {
                    break;
                }
                $retry_times++;
            } while($retry_times < self::MAX_RETRY_TIMES);

            if($ret['httpcode'] != 200 
                    || $ret['code'] != 0) {
                return $ret;
            }

            if ($ret['data']['session']) {
                $session = 
                    $ret['data']['session'];
            }
            $offset += $sliceSize;
        }

        return $ret;
    }


    private static function generateSliceBody(
            $fileContent, $offset, $sha, 
            $session, $fileName, $boundary) {
        $formdata = '';

        $formdata .= '--' . $boundary . "\r\n";
        $formdata .= "content-disposition: form-data; name=\"op\"\r\n\r\nupload_slice\r\n";

        $formdata .= '--' . $boundary . "\r\n";
        $formdata .= "content-disposition: form-data; name=\"offset\"\r\n\r\n" . $offset. "\r\n";


        $formdata .= '--' . $boundary . "\r\n";
        $formdata .= "content-disposition: form-data; name=\"session\"\r\n\r\n" . $session . "\r\n";

        $formdata .= '--' . $boundary . "\r\n";
        $formdata .= "content-disposition: form-data; name=\"fileContent\"; filename=\"" . $fileName . "\"\r\n"; 
        $formdata .= "content-type: application/octet-stream\r\n\r\n";

        $data = $formdata . $fileContent . "\r\n--" . $boundary . "--\r\n";

        return $data;
    }

    /*
     * 创建目录
     * @param  string  $bucketName
     * @param  string  $path 目录路径，sdk会补齐末尾的 '/'
     *
     */
    public static function createFolder($bucketName, $path,
                  $bizAttr = null) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }
        if (preg_match('/\/$/', $path) == 0) {
            $path = $path . '/';
        }
        $path = self::videoUrlEncode($path);

        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $path);
        $sign = Auth::appSign($expired, $bucketName);

        $data = array(
            'op' => 'create',
            'biz_attr' => (isset($bizAttr) ? $bizAttr : ''),
        );
        
        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization:'.$sign,
                'Content-Type: application/json',
            ),
        );

        return self::sendRequest($req);
    }

    /*
     * 目录列表,前缀搜索
     * @param  string  $bucketName
     * @param  string  $path     目录路径 web.file.myqcloud.com/files/v1/[appid]/[bucket_name]/[DirName]/
     *                           web.file.myqcloud.com/files/v1/appid/[bucket_name]/[DirName]/[prefix] <- 如果填写prefix, 则列出含此前缀的所有文件
     * @param  int     $num      拉取的总数
     * @param  string  $pattern  eListBoth,ListDirOnly,eListFileOnly  默认both
     * @param  int     $order    默认正序(=0), 填1为反序,
     * @param  string  $offset   透传字段,用于翻页,前端不需理解,需要往前/往后翻页则透传回来
     *  
     */
    public static function listFolder(
                    $bucketName, $path, $num = 20, 
                    $pattern = 'eListBoth', $order = 0, 
                    $context = null) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }
        if (preg_match('/\/$/', $path) == 0) {
            $path = $path . '/';
        }

        return self::listBase($bucketName, $path, $num,
                $pattern, $order, $context);
    }

    /*
     * 前缀搜索
     * @param  string  $bucketName
     * @param  string  $prefix   列出含此前缀的所有文件
     * @param  int     $num      拉取的总数
     * @param  string  $pattern  eListBoth,ListDirOnly,eListFileOnly  默认both
     * @param  int     $order    默认正序(=0), 填1为反序,
     * @param  string  $offset   透传字段,用于翻页,前端不需理解,需要往前/往后翻页则透传回来
     *  
     */
    public static function prefixSearch(
                    $bucketName, $prefix, $num = 20, 
                    $pattern = 'eListBoth', $order = 0, 
                    $context = null) {

        if (preg_match('/^\//', $prefix) == 0) {
            $prefix = '/' . $prefix;
        }

        return self::listBase($bucketName, $prefix, $num,
                $pattern, $order, $context);
    }

    private static function listBase(
                    $bucketName, $path, $num = 20, 
                    $pattern = 'eListBoth', $order = 0, $context = null) {

        $path = self::videoUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $path);
        $sign = Auth::appSign($expired, $bucketName);

        $data = array(
            'op' => 'list',
            'num' => $num,
            'pattern' => $pattern,
            'order' => $order,
            'context' => $context,
        );
        
        //$data = json_encode($data);
        $url = $url . '?' . http_build_query($data);

        $req = array(
            'url' => $url,
            'method' => 'get',
            'timeout' => self::$timeout,
            'header' => array(
                'Authorization:'.$sign,
            ),
        );

        return self::sendRequest($req);
    } 


    /*
     * 更新目录信息 updateFolder
     * @param  string  $bucketName
     * @param  string  $path 路径， sdk会补齐末尾的 '/'
     *
     */
    public static function updateFolder($bucketName, $path, 
                  $bizAttr) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }
        if (preg_match('/\/$/', $path) == 0) {
            $path = $path . '/';
        }

        return self::updateBase($bucketName, $path, $bizAttr);
    }

    /*
     * 更新文件信息 update
     * @param  string  $bucketName
     * @param  string  $path       路径
     * @param  string  $videoCover 视频封面url
     *
     */
    public static function update($bucketName, $path, $videoCover = null, 
                  $bizAttr = null, $title = null, $desc = null) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }

        return self::updateBase($bucketName, $path, $bizAttr, $videoCover, $title, $desc);
    }

    private static function updateBase($bucketName, $path, 
                  $bizAttr = null, $videoCover = null, $title = null, $desc = null) {

        $path = self::videoUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $path);
        $sign = Auth::appSign_once(
                $path, $bucketName);

        $flag = 0;
        if ($title != null && $desc != null && $bizAttr != null && $videoCover != null) {
            $flag = Conf::eMaskAll;
        } else {
            if ($title != null) {
                $flag |= Conf::eMaskTitle;
            }
            if ($desc != null) {
                $flag |= Conf::eMaskDesc;
            }
            if ($bizAttr != null) {
                $flag |= Conf::eMaskBizAttr;
            }
            if ($videoCover != null) {
                $flag |= Conf::eMaskVideoCover;
            }
        }
        $data = array(
            'op' => 'update',
            'biz_attr' => $bizAttr,
            'video_cover'  => $videoCover,
            'video_title' => $title,
            'video_desc' => $desc,
            'flag' => $flag,
        );
        
        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization:'.$sign,
                'Content-Type: application/json',
            ),
        );

        return self::sendRequest($req);
    }

    /*
     * 目录信息 查询
     * @param  string  $bucketName
     * @param  string  $path 路径，sdk会补齐末尾的 '/'
     *  
     */
    public static function statFolder(
                    $bucketName, $path) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }
        if (preg_match('/\/$/', $path) == 0) {
            $path = $path . '/';
        }

        return self::statBase($bucketName, $path);
    }

    /*
     * 文件信息 查询
     * @param  string  $bucketName
     * @param  string  $path 路径
     *  
     */
    public static function stat(
                    $bucketName, $path) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }

        return self::statBase($bucketName, $path);
    }

    private static function statBase(
                    $bucketName, $path) {

        $path = self::videoUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $path);
        $sign = Auth::appSign($expired, $bucketName);

        $data = array(
            'op' => 'stat',
        );

        //$data = json_encode($data);
        $url = $url . '?' . http_build_query($data);

        $req = array(
            'url' => $url,
            'method' => 'get',
            'timeout' => self::$timeout,
            'header' => array(
                'Authorization:'.$sign,
            ),
        );

        return self::sendRequest($req);
    } 

    /*
     * 删除目录
     * @param  string  $bucketName
     * @param  string  $path 路径，sdk会补齐末尾的 '/'
     *                       注意不能删除bucket下根目录/
     *
     */
    public static function delFolder($bucketName, $path) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }
        if (preg_match('/\/$/', $path) == 0) {
            $path = $path . '/';
        }

        return self::delBase($bucketName, $path);
    }

    /*
     * 删除文件
     * @param  string  $bucketName
     * @param  string  $path 路径
     *
     */
    public static function del($bucketName, $path) {
        if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }

        return self::delBase($bucketName, $path);
    }

    private static function delBase($bucketName, $path) {
        if ($path == "/") {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'can not delete bucket using api! go to http://console.qcloud.com/uvs to operate bucket',
                    );
        }

        $path = self::videoUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucketName, $path);
        $sign = Auth::appSign_once(
                $path, $bucketName);

        $data = array(
            'op' => 'delete',
        );
        
        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization:'.$sign,
                'Content-Type: application/json',
            ),
        );

        return self::sendRequest($req);
    }
    
//end of script
}

