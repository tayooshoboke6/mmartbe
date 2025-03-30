<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Get bank transfer settings
     */
    public function getBankTransferSettings()
    {
        try {
            $settings = Setting::whereIn('key', [
                'bank_name',
                'bank_account_number',
                'bank_account_name'
            ])->get();

            $bankDetails = [];
            foreach ($settings as $setting) {
                $bankDetails[$setting->key] = $setting->value;
            }

            return response()->json([
                'status' => 'success',
                'data' => $bankDetails
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch bank transfer settings'
            ], 500);
        }
    }
}
