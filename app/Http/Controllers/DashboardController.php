<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\HostingService;
use App\Models\SslCertificate;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $search = trim((string) request('q', ''));
        $isAdmin = $user?->hasRole('Administrator') ?? false;
        $clientIds = $isAdmin ? null : $user?->clients()->pluck('clients.id');

        $domainsQuery = Domain::query()->with('client')->orderBy('name');
        $hostingQuery = HostingService::query()->with('client', 'domain')->orderBy('next_renewal_due');
        $sslQuery = SslCertificate::query()->with('client', 'domain')->orderBy('expire_date');

        if (! $isAdmin && $clientIds !== null) {
            $domainsQuery->whereIn('client_id', $clientIds);
            $hostingQuery->whereIn('client_id', $clientIds);
            $sslQuery->whereIn('client_id', $clientIds);
        }

        if ($search !== '') {
            $domainsQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%')
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('business_name', 'like', '%'.$search.'%');
                    });
            });

            $hostingQuery->where(function ($query) use ($search) {
                $query->where('username', 'like', '%'.$search.'%')
                    ->orWhere('plan', 'like', '%'.$search.'%')
                    ->orWhere('server', 'like', '%'.$search.'%')
                    ->orWhere('ip_address', 'like', '%'.$search.'%')
                    ->orWhereHas('domain', function ($domainQuery) use ($search) {
                        $domainQuery->where('name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('business_name', 'like', '%'.$search.'%');
                    });
            });

            $sslQuery->where(function ($query) use ($search) {
                $query->where('common_name', 'like', '%'.$search.'%')
                    ->orWhere('product_name', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%')
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('business_name', 'like', '%'.$search.'%');
                    });
            });
        }

        $domains = $domainsQuery->limit(50)->get();
        $hostingServices = $hostingQuery->limit(50)->get();
        $sslCertificates = $sslQuery->limit(50)->get();

        return view('dashboard', compact('search', 'domains', 'hostingServices', 'sslCertificates'));
    }
}
