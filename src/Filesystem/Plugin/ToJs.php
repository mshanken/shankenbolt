<?php

namespace Bolt\Filesystem\Plugin;

use Bolt\Filesystem\Handler\ImageInterface;
use Bolt\Filesystem\PluginInterface;

/**
 * Returns intrinsic data about file as well as some pre-generated links for JS to use.
 *
 * Goal of this plugin is to always give JS consistent data about a file in a DRY way.
 */
class ToJs implements PluginInterface
{
    use PluginTrait;

    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return 'toJs';
    }

    public function handle($path)
    {
        $file = $this->filesystem->getFile($path);

        $result = [
            'filename'  => $file->getFilename(),
            'path'      => $file->getPath(),
            'fullPath'  => $file->getFullPath(),
            'extension' => $file->getExtension(),
        ];
        if ($file instanceof ImageInterface) {
            // Rewrite the deprecated /thumbs/ URLs to Imgix, mirroring
            // ImageRuntime::convertThumbnailToImgixUrl(), so that images
            // uploaded/replaced in the CMS preview correctly instead of
            // pointing at the retired ws-cms-thumbs service.
            $result['previewUrl'] = $this->convertThumbnailToImgixUrl($file->thumb(200, 150, 'c'));
            $result['previewListUrl'] = $this->convertThumbnailToImgixUrl($file->thumb(60, 40, 'c'));
        }
        try {
            $result['url'] = $file->url();
        } catch (\Exception $e) {
        }

        return $result;
    }

    /**
     * Convert a thumbnail URL path to an Imgix URL with query parameters.
     *
     * Kept intentionally in sync with ImageRuntime::convertThumbnailToImgixUrl().
     *
     * @param string $relativePath Path such as '/thumbs/200x150c/path/to/image.jpg'
     *
     * @return string The Imgix URL with query parameters
     */
    private function convertThumbnailToImgixUrl($relativePath)
    {
        $relativePath = ltrim((string) $relativePath, '/');

        // Extract dimensions and action from the path
        if (preg_match('#^thumbs/([0-9]+)x([0-9]+)([a-z])/(.+)$#i', $relativePath, $matches)) {
            $width = $matches[1];
            $height = $matches[2];
            $action = $matches[3];
            $filePath = $matches[4];

            // Determine fit parameter based on action
            $fit = 'crop'; // Default for 'c'
            if ($action === 'r') {
                $fit = 'max';
            } elseif ($action === 'b') {
                $fit = 'pad';
            } elseif ($action === 'f') {
                $fit = 'fill';
            }

            // Build the URL with query parameters
            return 'https://mshanken.imgix.net/wso/bolt/' . $filePath .
                   '?w=' . $width .
                   '&h=' . $height .
                   '&fit=' . $fit .
                   '&auto=compress,format&sharp=5&vib=20&q=70';
        }

        // If the pattern doesn't match, return the original URL
        return 'https://mshanken.imgix.net/wso/bolt/' . $relativePath;
    }
}
