<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Domain;
use App\Models\HostingService;
use App\Services\Halo\HaloPsaClient;

class TicketController extends Controller
{
    public function create()
    {
        $user = auth()->user();
        $clients = $user->hasRole('Administrator') ? \App\Models\Client::all() : $user->clients;
        $domains = \App\Models\Domain::whereIn('client_id', $clients->pluck('id'))->get();
        $services = \App\Models\HostingService::whereIn('client_id', $clients->pluck('id'))->get();
        return view('tickets.create', compact('domains','services'));
    }

    public function store(Request $request, HaloPsaClient $halo)
    {
        $data = $request->validate([
            'subject'      => 'required',
            'message'      => 'required',
            'client_id'    => 'required|exists:clients,id',
            'type'         => 'required|in:domain,service',
            'reference_id' => 'required',
        ]);

        try {
            $ticket = $halo->createTicket([
                'Summary'      => $data['subject'],
                'Details'      => $data['message'],
                'ClientId'     => $data['client_id'],
                'CustomFields' => [
                    [
                        'Name'  => 'DomainDash Reference',
                        'Value' => $data['type'] . ':' . $data['reference_id'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'halo' => 'Unable to contact HaloPSA API: ' . $e->getMessage(),
            ]);
        }

        return redirect()->back()->with('status', 'Ticket logged to HaloPSA');
    }

}
