<?php

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Create a simple test for Cloudinary
echo "Testing Cloudinary Configuration\n";
echo "================================\n";
echo "CLOUDINARY_CLOUD_NAME: " . ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'Not set') . "\n";
echo "CLOUDINARY_API_KEY: " . ($_ENV['CLOUDINARY_API_KEY'] ? 'Set' : 'Not set') . "\n";
echo "CLOUDINARY_SECRET_KEY: " . ($_ENV['CLOUDINARY_SECRET_KEY'] ? 'Set' : 'Not set') . "\n\n";

// Test the placeholder URL generation (this doesn't require Cloudinary credentials)
echo "Testing Placeholder URL Generation\n";
echo "================================\n";
$productName = "Test Product";
$placeholderUrl = "https://placehold.co/600x400?font=roboto&text=" . urlencode(str_replace(' ', '\n', $productName));
echo "Placeholder URL for '$productName': $placeholderUrl\n\n";

// Only proceed with Cloudinary tests if credentials are set
if (!empty($_ENV['CLOUDINARY_CLOUD_NAME']) && !empty($_ENV['CLOUDINARY_API_KEY']) && !empty($_ENV['CLOUDINARY_SECRET_KEY'])) {
    echo "Testing Cloudinary Upload\n";
    echo "========================\n";
    
    // Initialize Cloudinary with credentials from .env
    $cloudinary = new Cloudinary\Cloudinary([
        'cloud' => [
            'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'],
            'api_key' => $_ENV['CLOUDINARY_API_KEY'],
            'api_secret' => $_ENV['CLOUDINARY_SECRET_KEY'],
        ],
    ]);
    
    // This is a very small base64 encoded red dot image
    $base64Image = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAHElEQVQI12P4//8/w38GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==";
    
    try {
        $result = $cloudinary->uploadApi()->upload($base64Image, [
            'folder' => 'tests',
            'public_id' => 'test-' . time(),
        ]);
        
        echo "Successfully uploaded image to Cloudinary!\n";
        echo "Uploaded URL: " . $result['secure_url'] . "\n";
    } catch (\Exception $e) {
        echo "Error uploading to Cloudinary: " . $e->getMessage() . "\n";
    }
} else {
    echo "Skipping Cloudinary upload tests because credentials are not fully configured.\n";
}

echo "\nTest completed!\n";
