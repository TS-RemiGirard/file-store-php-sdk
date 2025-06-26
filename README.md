# TooSmart FileStore SDK
A PHP SDK for interacting with the TooSmart Object Storage API.
Easily authenticate, upload, and download files from your TooSmart storage buckets.

## Features
- Authenticate with API key (JWT-based)

- Upload files, file paths, or HTML content to storage buckets

- Download files from a specified bucket

- Automatic JWT renewal on authentication expiry (401)

## Requirements
- PHP 7.4 or higher

- Guzzle HTTP

- TooSmart Object Storage API access

## Installation
### Install via Composer (recommended):

```bash
composer require oosmart/file-store-sdk
```

Or manually include the SDK and its dependencies in your project.

## Usage
1. Initialize the Client
```php
use TooSmart\FileStoreSdk\Client;

$client = new Client('https://your-filestore-api.url', 'your-api-key');
```

2. Authenticate
```php
$client->login();
```

3. Select a Storage Bucket
```php
$client->setBucket('your-bucket-name');
```

4. Download a File
```php
$response = $client->getFile('path/in/bucket/filename.pdf');
$fileContents = $response['body']; // If not JSON, this is the raw file data
```

5. Upload Content
You can upload files, file paths, or HTML content.
Each item in $contentList must be an array with keys type (file, path, or html) and value.

Example: Upload a File
```php
$contentList = [
    ['type' => 'file', 'value' => '/local/path/to/file.pdf'],
];

$response = $client->uploadContentList('folder/filename.pdf', $contentList, 'application/pdf', true);
$linkToFile = $response['body']['url'] ?? null;
```
Example: Upload HTML Content
```php
$contentList = [
    ['type' => 'html', 'value' => '<h1>Hello, World!</h1>'],
];

$response = $client->uploadContentList('folder/hello.html', $contentList, 'text/html', true);
```
