<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Transformation\Resize;
use Cloudinary\Transformation\Gravity;
use Cloudinary\Transformation\Quality;
use Illuminate\Http\UploadedFile;

class CloudinaryService
{
    protected $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                'api_key' => env('CLOUDINARY_API_KEY'),
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ],
        ]);
    }

    /**
     * Upload an image to Cloudinary with compression and optimization
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return string
     */
    public function uploadImage(UploadedFile $file, string $folder = 'products'): string
    {
        $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder' => $folder,
            'transformation' => [
                ['quality' => 'auto:good'],
                ['fetch_format' => 'auto'],
                ['width' => 800, 'crop' => 'limit'],
            ],
        ]);

        return $result['secure_url'];
    }

    /**
     * Upload a product image with specific transformations
     *
     * @param UploadedFile $file
     * @param string $productSku
     * @return string
     */
    public function uploadProductImage(UploadedFile $file, string $productSku): string
    {
        $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder' => 'products',
            'public_id' => $productSku . '-' . time(),
            'transformation' => [
                ['quality' => 'auto:good'],
                ['fetch_format' => 'auto'],
                ['width' => 800, 'height' => 800, 'crop' => 'fill', 'gravity' => 'auto'],
            ],
        ]);

        return $result['secure_url'];
    }

    /**
     * Upload a base64 encoded image to Cloudinary
     *
     * @param string $base64Image
     * @param string $publicId
     * @return string
     */
    public function uploadBase64Image(string $base64Image, string $publicId): string
    {
        $result = $this->cloudinary->uploadApi()->upload($base64Image, [
            'folder' => 'products',
            'public_id' => $publicId,
            'transformation' => [
                ['quality' => 'auto:good'],
                ['fetch_format' => 'auto'],
                ['width' => 800, 'height' => 800, 'crop' => 'limit'],
            ],
        ]);

        return $result['secure_url'];
    }

    /**
     * Generate a placeholder image URL based on text
     *
     * @param string $text
     * @param int $width
     * @param int $height
     * @return string
     */
    public function generatePlaceholderUrl(string $text, int $width = 600, int $height = 400): string
    {
        $formattedText = str_replace(' ', '\n', $text);
        return "https://placehold.co/{$width}x{$height}?font=roboto&text=" . urlencode($formattedText);
    }
}
