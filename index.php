<?php
// s3server.php

// Configuration
define('DATA_DIR', __DIR__ . '/data'); // ./data/{bucket}/{key}
define('ALLOWED_ACCESS_KEYS', ['put-your-key-here']);
define('MAX_REQUEST_SIZE', 100 * 1024 * 1024); // 100MB

// Helper functions
function extract_access_key_id($authorization) {
    if (empty($authorization)) return null;
    if (preg_match('/AWS4-HMAC-SHA256 Credential=([^\/]+)\//', $authorization, $matches)) {
        return $matches[1];
    }
    return null;
}

function auth_check() {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $access_key_id = extract_access_key_id($authorization);
    if (!$access_key_id || !in_array($access_key_id, ALLOWED_ACCESS_KEYS)) {
        http_response_code(401);
        exit;
    }
    return true;
}

function generate_s3_error_response($code, $message, $resource = '') {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Error></Error>');
    $xml->addChild('Code', $code);
    $xml->addChild('Message', $message);
    $xml->addChild('Resource', $resource);
    
    header('Content-Type: application/xml');
    http_response_code((int)$code);
    echo $xml->asXML();
    exit;
}

function generate_s3_list_objects_response($files, $bucket, $prefix = '') {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult></ListBucketResult>');
    $xml->addChild('Name', $bucket);
    $xml->addChild('Prefix', $prefix);
    $xml->addChild('MaxKeys', '1000');
    $xml->addChild('IsTruncated', 'false');
    
    foreach ($files as $file) {
        $contents = $xml->addChild('Contents');
        $contents->addChild('Key', $file['key']);
        $contents->addChild('LastModified', date('Y-m-d\TH:i:s.000\Z', $file['timestamp']));
        $contents->addChild('Size', $file['size']);
        $contents->addChild('StorageClass', 'STANDARD');
    }
    
    header('Content-Type: application/xml');
    echo $xml->asXML();
    exit;
}

function generate_s3_create_multipart_upload_response($bucket, $key, $uploadId) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult></InitiateMultipartUploadResult>');
    $xml->addChild('Bucket', $bucket);
    $xml->addChild('Key', $key);
    $xml->addChild('UploadId', $uploadId);
    
    header('Content-Type: application/xml');
    echo $xml->asXML();
    exit;
}

function generate_s3_complete_multipart_upload_response($bucket, $key, $uploadId, $etag) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult></CompleteMultipartUploadResult>');
    $xml->addChild('Location', "http://{$_SERVER['HTTP_HOST']}/{$bucket}/{$key}");
    $xml->addChild('Bucket', $bucket);
    $xml->addChild('Key', $key);
    $xml->addChild('UploadId', $uploadId);
    $xml->addChild('ETag', '"' . $etag . '"');
    
    header('Content-Type: application/xml');
    echo $xml->asXML();
    exit;
}

function list_files($bucket, $prefix = '') {
    $dir = DATA_DIR . "/{$bucket}";
    $files = [];
    
    if (!file_exists($dir)) return $files;
    
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    
    foreach ($iterator as $file) {
        if ($file->isDir() || strpos($file->getFilename(), '.') === 0) continue;
        
        $relativePath = substr($file->getPathname(), strlen($dir) + 1);
        
        if ($prefix && strpos($relativePath, $prefix) !== 0) continue;
        
        $files[] = [
            'key' => $relativePath,
            'size' => $file->getSize(),
            'timestamp' => $file->getMTime()
        ];
    }
    
    return $files;
}

// Ensure DATA_DIR exists
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}


// 主请求处理逻辑
$method = $_SERVER['REQUEST_METHOD'];

// Parce url
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($request_uri, '/'));
$bucket = $path_parts[0] ?? '';
$key = implode('/', array_slice($path_parts, 1));

if ($method !== 'GET' && empty($bucket)) {
    generate_s3_error_response('400', 'Bucket name not specified', '/');
}

if ($method === 'GET' && empty($bucket)) {
    generate_s3_error_response('400', 'Bucket name required', '/');
}

// Auth check
auth_check();


// Check request size
if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > MAX_REQUEST_SIZE) {
    generate_s3_error_response('413', 'Request too large');
}

// Route requests
switch ($method) {
    case 'PUT':
        // Handle PUT (upload object or part)
        if (isset($_GET['partNumber']) && isset($_GET['uploadId'])) {
            // Upload part
            $uploadId = $_GET['uploadId'];
            $partNumber = $_GET['partNumber'];
            $uploadDir = DATA_DIR . "/{$bucket}/{$key}-temp/{$uploadId}";
            
            if (!file_exists($uploadDir)) {
                generate_s3_error_response('404', 'Upload ID not found', "/{$bucket}/{$key}");
            }
            
            $partPath = "{$uploadDir}/{$partNumber}";
            file_put_contents($partPath, file_get_contents('php://input'));
            
            header('ETag: ' . md5_file($partPath));
            http_response_code(200);
            exit;
        } else {
            // Upload single object
            $filePath = DATA_DIR . "/{$bucket}/{$key}";
            $dir = dirname($filePath);
            
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            
            file_put_contents($filePath, file_get_contents('php://input'));
            $etag = md5_file($filePath);
            header('ETag: "' . $etag . '"');
            http_response_code(200);
            exit;
        }
        break;
        
    case 'POST':
        // Handle POST (multipart upload)
        if (isset($_GET['uploads'])) {
            // Initiate multipart upload
            $uploadId = bin2hex(random_bytes(16));
            $uploadDir = DATA_DIR . "/{$bucket}/{$key}-temp/{$uploadId}";
            mkdir($uploadDir, 0777, true);
            
            generate_s3_create_multipart_upload_response($bucket, $key, $uploadId);
        } elseif (isset($_GET['uploadId'])) {
            // Complete multipart upload
            $uploadId = $_GET['uploadId'];
            $uploadDir = DATA_DIR . "/{$bucket}/{$key}-temp/{$uploadId}";
            
            if (!file_exists($uploadDir)) {
                generate_s3_error_response('404', 'Upload ID not found', "/{$bucket}/{$key}");
            }
            
            // Parse parts from XML
            $xml = simplexml_load_string(file_get_contents('php://input'));
            $parts = [];
            foreach ($xml->Part as $part) {
                $parts[(int)$part->PartNumber] = (string)$part->ETag;
            }
            ksort($parts);
            
            // Merge parts
            $filePath = DATA_DIR . "/{$bucket}/{$key}";
            $dir = dirname($filePath);
            if (!file_exists($dir)) mkdir($dir, 0777, true);
            
            $fp = fopen($filePath, 'w');
            foreach (array_keys($parts) as $partNumber) {
                $partPath = "{$uploadDir}/{$partNumber}";
                if (!file_exists($partPath)) {
                    generate_s3_error_response('500', "Part file missing: {$partNumber}", "/{$bucket}/{$key}");
                }
                fwrite($fp, file_get_contents($partPath));
            }
            fclose($fp);

            // Calculate S3-style multipart ETag
            $md5s = [];
            foreach (array_keys($parts) as $partNumber) {
                $partPath = "{$uploadDir}/{$partNumber}";
                $md5s[] = pack('H*', md5_file($partPath));
            }
            $etag = md5(implode('', $md5s)) . '-' . count($md5s);
                        
            // Clean up
            system("rm -rf " . escapeshellarg(DATA_DIR . "/{$bucket}/{$key}-temp"));
            
            generate_s3_complete_multipart_upload_response($bucket, $key, $uploadId, $etag);
        } else {
            generate_s3_error_response('400', 'Invalid POST request: missing uploads or uploadId parameter', "/{$bucket}/{$key}");
        }
        break;
        
    case 'GET':
        // Handle GET (download or list)
        if (empty($key)) {
            // List objects
            $prefix = $_GET['prefix'] ?? '';
            $files = list_files($bucket, $prefix);
            generate_s3_list_objects_response($files, $bucket, $prefix);
        } else {
            // Download object
            $filePath = DATA_DIR . "/{$bucket}/{$key}";
            
            if (!file_exists($filePath)) {
                generate_s3_error_response('404', 'Object not found', "/{$bucket}/{$key}");
            }
            
            header('Content-Type: ' . mime_content_type($filePath));
            header('Content-Length: ' . filesize($filePath));
            $etag = md5_file($filePath);
            header('ETag: "' . $etag . '"');
            readfile($filePath);
            exit;
        }
        break;
        
    case 'HEAD':
        // Handle HEAD (metadata)
        $filePath = DATA_DIR . "/{$bucket}/{$key}";
        
        if (!file_exists($filePath)) {
            generate_s3_error_response('404', 'Resource not found', "/{$bucket}/{$key}");
        }
        
        header('Content-Length: ' . filesize($filePath));
        header('Content-Type: ' . mime_content_type($filePath));
        $etag = md5_file($filePath);
        header('ETag: "' . $etag . '"');
        http_response_code(200);
        exit;
        
    case 'DELETE':
        // Handle DELETE (delete object or abort upload)
        if (isset($_GET['uploadId'])) {
            // Abort multipart upload
            $uploadId = $_GET['uploadId'];
            $uploadDir = DATA_DIR . "/{$bucket}/{$key}-temp/{$uploadId}";
            
            if (!file_exists($uploadDir)) {
                generate_s3_error_response('404', 'Upload ID not found', "/{$bucket}/{$key}");
            }
            
            system("rm -rf " . escapeshellarg($uploadDir));
            http_response_code(204);
            exit;
        } else {
            // Delete object
            $filePath = DATA_DIR . "/{$bucket}/{$key}";
            
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            http_response_code(204);
            exit;
        }
        break;
        
    default:
        generate_s3_error_response('405', 'Method not allowed');
}