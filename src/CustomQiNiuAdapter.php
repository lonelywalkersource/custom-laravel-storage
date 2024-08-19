<?php
/**
 * User: lonely walker
 * Date: 2024/8/19 10:17
 * Desc:
 */

namespace LonelyWalker\CustomLaravelStorage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Qiniu\Auth;
use Qiniu\Cdn\CdnManager;
use Qiniu\Http\Error;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

class CustomQiNiuAdapter extends BaseAdapter
{
    protected ?Auth $authManager = null;
    protected ?UploadManager $uploadManager = null;
    protected ?BucketManager $bucketManager = null;
    protected ?CdnManager $cdnManager = null;
    public function __construct(
        protected string $accessKey,
        protected string $secretKey,
        protected string $bucket,
        protected string $domain
    ) {}

    public function getBucketManager()
    {
        return $this->bucketManager ?: $this->bucketManager = new BucketManager($this->getAuthManager());
    }

    public function getAuthManager()
    {
        return $this->authManager ?: $this->authManager = new Auth($this->accessKey, $this->secretKey);
    }

    public function getCdnManager()
    {
        return $this->cdnManager ?: $this->cdnManager = new CdnManager($this->getAuthManager());
    }

    public function getUploadManager()
    {
        return $this->uploadManager ?: $this->uploadManager = new UploadManager;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getUrl(string $path): string
    {
        $segments = $this->parseUrl($path);
        $query = empty($segments['query']) ? '' : '?'.$segments['query'];

        return $this->normalizeHost($this->domain).ltrim(implode('/', array_map('rawurlencode', explode('/', $segments['path']))), '/').$query;
    }

    /**
     * For laravel FilesystemAdapter.
     *
     * @param string $path
     */
    public function getTemporaryUrl($path, int|string|\DateTimeInterface $expiration, array $options = [], string $method = 'GET'): string
    {
        if ($expiration instanceof \DateTimeInterface) {
            $expiration = $expiration->getTimestamp();
        }

        if (is_string($expiration)) {
            $expiration = strtotime($expiration);
        }

        return $this->privateDownloadUrl($path, $expiration);
    }

    public function privateDownloadUrl(string $path, int $expires = 3600): string
    {
        return $this->getAuthManager()->privateDownloadUrl($this->getUrl($path), $expires);
    }

    /**
     * {@inheritDoc}
     */
    public function fileExists(string $path): bool
    {
        [, $error] = $this->getBucketManager()->stat($this->bucket, $path);

        return is_null($error);
    }

    /**
     * {@inheritDoc}
     */
    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $mime = $config->get('mime', 'application/octet-stream');

        /**
         * @var Error|null $error
         */
        [, $error] = $this->getUploadManager()->put(
            $this->getAuthManager()->uploadToken($this->bucket),
            $path,
            $contents,
            null,
            $mime,
            $path
        );

        if ($error) {
            throw UnableToWriteFile::atLocation($path, $error->message());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $data = '';

        while (! feof($contents)) {
            $data .= fread($contents, 1024);
        }

        $this->write($path, $data, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path): string
    {
        try {
            $result = file_get_contents($this->privateDownloadUrl($path));
        } catch (\Exception $th) {
            throw UnableToReadFile::fromLocation($path);
        }

        if ($result === false) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path)
    {
        if (ini_get('allow_url_fopen')) {
            if ($result = fopen($this->privateDownloadUrl($path), 'r')) {
                return $result;
            }
        }

        throw UnableToReadFile::fromLocation($path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): void
    {
        [, $error] = $this->getBucketManager()->delete($this->bucket, $path);

        if (! is_null($error)) {
            throw UnableToDeleteFile::atLocation($path);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    /**
     * {@inheritDoc}
     */
    public function createDirectory(string $path, Config $config): void {}

    /**
     * {@inheritDoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path);
    }

    /**
     * {@inheritDoc}
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path);
    }

    /**
     * {@inheritDoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);

        if ($meta->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return $meta;
    }

    /**
     * {@inheritDoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);

        if ($meta->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $meta;
    }

    /**
     * {@inheritDoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);

        if ($meta->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $meta;
    }

    /**
     * {@inheritDoc}
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $result = $this->getBucketManager()->listFiles($this->bucket, $path);

        foreach ($result[0]['items'] ?? [] as $files) {
            yield $this->normalizeFileInfo($files);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        [, $error] = $this->getBucketManager()->rename($this->bucket, $source, $destination);

        if (! is_null($error)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        [, $error] = $this->getBucketManager()->copy($this->bucket, $source, $this->bucket, $destination);

        if (! is_null($error)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    public function getUploadToken(?string $key = null, int $expires = 3600, ?array $policy = null, ?string $strictPolice = null): string
    {
        return $this->getAuthManager()->uploadToken($this->bucket, $key, $expires, $policy, $strictPolice);
    }

    public function verifyCallback(?string $contentType = null, ?string $originAuthorization = null, ?string $url = null, ?string $body = null)
    {
        return $this->getAuthManager()->verifyCallback($contentType, $originAuthorization, $url, $body);
    }

    protected function normalizeHost($domain): string
    {
        if (! str_starts_with($domain, 'https://') && ! str_starts_with($domain, 'http://')) {
            $domain = "http://{$domain}";
        }

        return rtrim($domain, '/').'/';
    }

    protected static function parseUrl($url): array
    {
        $result = [];
        // Build arrays of values we need to decode before parsing
        $entities = ['%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%24', '%2C', '%2F', '%3F', '%23', '%5B', '%5D', '%5C'];
        $replacements = ['!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '$', ',', '/', '?', '#', '[', ']', '/'];
        // Create encoded URL with special URL characters decoded so it can be parsed
        // All other characters will be encoded
        $encodedURL = str_replace($entities, $replacements, urlencode($url));

        // Parse the encoded URL
        $encodedParts = parse_url($encodedURL);

        // Now, decode each value of the resulting array
        if ($encodedParts) {
            foreach ($encodedParts as $key => $value) {
                $result[$key] = urldecode(str_replace($replacements, $entities, $value));
            }
        }

        return $result;
    }

    protected function getMetadata($path): FileAttributes|array
    {
        $result = $this->getBucketManager()->stat($this->bucket, $path);
        $result[0]['key'] = $path;

        return $this->normalizeFileInfo($result[0]);
    }

    protected function normalizeFileInfo(array $stats): FileAttributes
    {
        return new FileAttributes(
            $stats['key'],
            $stats['fsize'] ?? null,
            null,
            isset($stats['putTime']) ? floor($stats['putTime'] / 10000000) : null,
            $stats['mimeType'] ?? null
        );
    }
}