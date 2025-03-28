<?php

namespace App\Console\Commands;

use App\Models\Address;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateAddressCoordinates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'addresses:update-coordinates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update existing addresses with latitude and longitude coordinates';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $addresses = Address::whereNull('latitude')
            ->orWhereNull('longitude')
            ->get();

        $this->info("Found {$addresses->count()} addresses without coordinates.");

        if ($addresses->isEmpty()) {
            $this->info('No addresses need updating.');
            return 0;
        }

        $googleApiKey = config('services.google.maps_api_key');
        
        if (!$googleApiKey) {
            $this->error('Google Maps API key not configured. Please set GOOGLE_MAPS_API_KEY in your .env file.');
            return 1;
        }

        $updated = 0;
        $failed = 0;

        $this->output->progressStart($addresses->count());

        foreach ($addresses as $address) {
            try {
                // Build address string for geocoding
                $addressString = urlencode("{$address->street}, {$address->city}, {$address->state}, {$address->country}");
                
                // Call Google Maps Geocoding API
                $response = Http::get("https://maps.googleapis.com/maps/api/geocode/json", [
                    'address' => $addressString,
                    'key' => $googleApiKey
                ]);
                
                $data = $response->json();
                
                if ($response->successful() && isset($data['results'][0]['geometry']['location'])) {
                    $location = $data['results'][0]['geometry']['location'];
                    
                    $address->latitude = $location['lat'];
                    $address->longitude = $location['lng'];
                    $address->save();
                    
                    $updated++;
                    $this->info("Updated address ID {$address->id}: {$address->street}");
                } else {
                    $failed++;
                    $this->warn("Failed to geocode address ID {$address->id}: {$address->street}");
                    Log::warning("Failed to geocode address", [
                        'address_id' => $address->id,
                        'address' => $addressString,
                        'response' => $data
                    ]);
                }
                
                // Sleep to avoid hitting API rate limits
                usleep(200000); // 200ms delay
            } catch (\Exception $e) {
                $failed++;
                $this->error("Error processing address ID {$address->id}: {$e->getMessage()}");
                Log::error("Error updating address coordinates", [
                    'address_id' => $address->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            $this->output->progressAdvance();
        }
        
        $this->output->progressFinish();
        
        $this->info("Completed: Updated {$updated} addresses, Failed: {$failed}");
        
        return 0;
    }
}
