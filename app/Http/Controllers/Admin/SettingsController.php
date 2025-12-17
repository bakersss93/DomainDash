<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'branding' => Setting::get('branding', ['primary'=>'#1f2937','accent'=>'#06b6d4','text'=>'#111827','bg'=>'#ffffff']),
            'smtp'     => Setting::get('smtp', []),
            'synergy'  => Setting::get('synergy', []),
            'halo'     => Setting::get('halo', []),
            'itglue'   => Setting::get('itglue', []),
            'ip2whois' => Setting::get('ip2whois', []),
            'backup'   => Setting::get('backup', ['host'=>'','port'=>22,'username'=>'','password'=>'','path'=>'/','retention'=>7,'time'=>'02:00']),
            'notifications' => Setting::get('notifications', ['disk_threshold_percent'=>90]),
        ];
        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'branding'      => 'array',
            'smtp'          => 'array',
            'synergy'       => 'array',
            'halo'          => 'array',
            'itglue'        => 'array',
            'ip2whois'      => 'array',
            'backup'        => 'array',
            'notifications' => 'array',
            'branding_logo' => 'nullable|file|image|max:2048', // up to 2MB
        ]);

        /**
         * Halo API key sentinel:
         * If the form posts "********" we keep the existing secret.
         */
        if (isset($data['halo']) && is_array($data['halo'])) {
            if (array_key_exists('api_key', $data['halo']) && $data['halo']['api_key'] === '********') {
                $currentHalo = Setting::get('halo', []);
                if (isset($currentHalo['api_key'])) {
                    $data['halo']['api_key'] = $currentHalo['api_key'];
                } else {
                    unset($data['halo']['api_key']);
                }
            }
        }

        /**
         * Synergy API key sentinel:
         * Same behaviour as Halo – "********" means keep existing key.
         */
        if (isset($data['synergy']) && is_array($data['synergy'])) {
            if (array_key_exists('api_key', $data['synergy']) && $data['synergy']['api_key'] === '********') {
                $currentSynergy = Setting::get('synergy', []);
                if (isset($currentSynergy['api_key'])) {
                    $data['synergy']['api_key'] = $currentSynergy['api_key'];
                } else {
                    unset($data['synergy']['api_key']);
                }
            }
        }

        // Handle logo upload if provided
        if ($request->hasFile('branding_logo')) {
            $file = $request->file('branding_logo');

            // Store on the "public" disk so Storage::url() works
            $path = $file->store('logos', 'public'); // e.g. logos/abcd1234.png

            // Merge into existing branding settings
            $branding = Setting::get('branding', []);
            $branding['logo'] = $path;

            Setting::put('branding', $branding);

            // Make sure we don't overwrite branding with an older array below
            unset($data['branding']);
        }

        // Save all other settings (and branding if present and not overwritten above)
        foreach ($data as $key => $value) {
            if ($key === 'branding') {
                $current = Setting::get('branding', []);
                Setting::put('branding', array_merge($current, $value ?? []));
            } else {
                Setting::put($key, $value);
            }
        }

        return back()->with('status', 'Settings saved.');
    }

    public function testSmtp(Request $request)
    {
        $request->validate(['to' => 'required|email']);

        // Load SMTP settings from database
        $smtp = Setting::get('smtp', []);

        // Validate that SMTP settings are configured
        if (empty($smtp['host']) || empty($smtp['port']) || empty($smtp['from'])) {
            return back()->withErrors(['smtp' => 'SMTP settings are not fully configured. Please configure host, port, and from address.']);
        }

        try {
            // Temporarily configure mail settings using database values
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $smtp['host'],
                'mail.mailers.smtp.port' => $smtp['port'],
                'mail.mailers.smtp.username' => $smtp['username'] ?? null,
                'mail.mailers.smtp.password' => $smtp['password'] ?? null,
                'mail.mailers.smtp.encryption' => $smtp['encryption'] ?? null,
                'mail.from.address' => $smtp['from'],
                'mail.from.name' => $smtp['from_name'] ?? 'DomainDash',
            ]);

            // Send test email
            Mail::raw('This is a test email from DomainDash. If you received this, your SMTP configuration is working correctly!', function($m) use ($request) {
                $m->to($request->to)
                  ->subject('DomainDash SMTP Test');
            });

            return back()->with('status', 'Test email sent successfully to ' . $request->to);
        } catch (\Exception $e) {
            return back()->withErrors(['smtp' => 'Failed to send test email: ' . $e->getMessage()]);
        }
    }
}
