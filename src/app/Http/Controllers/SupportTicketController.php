<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Notifications\CustomerNotificationService;
use App\Support\Notifications\CustomerNotificationPayload;

 class SupportTicketController extends Controller
{
    // Map DB -> API shape
    private function toApi(SupportTicket $t): array
    {
        return [
            'id'         => (int) $t->id,
            'reference'  => $t->Ticket_Reference,
            'subject'    => $t->Subject,
            'type'       => $t->Ticket_Type,
            'order_id'   => $t->Order_Id ? (int) $t->Order_Id : null,
            'status'     => $t->Ticket_Status,
            'created_at' => optional($t->created_at)?->toDateTimeString(),
            'updated_at' => optional($t->updated_at)?->toDateTimeString(),
        ];
    }

    private function toApiMessage(SupportTicketMessage $m): array
    {
        return [
            'id'         => (int) $m->id,
            'ticket_id'  => (int) $m->Ticket_Id,
            'sender'     => $m->Sender_Type,    // 'user' | 'support'
            'body'       => $m->Message_Body,
            'created_at' => optional($m->created_at)?->toDateTimeString(),
        ];
    }

    // Ownership check
    private function ownsTicket(SupportTicket $ticket, $user): bool
    {
        $userId = $user->User_Id ?? $user->id ?? null;
        $customerId = optional($user->customers)->id ?? null; // if you have $user->customers relation

        return ($ticket->User_Id && $ticket->User_Id == $userId)
            || ($ticket->Customer_Id && $ticket->Customer_Id == $customerId)
            || (!$ticket->User_Id && !$ticket->Customer_Id && $ticket->id === -1); // dummy always false
    }

    public function index()
    {
       

       
        $customerId = Auth::user()?->customers;

        $tickets = SupportTicket::query()
            ->when(Auth::id(), fn($q) => $q->orWhere('User_Id', Auth::id()))
            ->when($customerId, fn($q) => $q->orWhere('Customer_Id', $customerId->id))
            ->orderByDesc('id')
            ->get();

        return response()->json($tickets->map(fn($t) => $this->toApi($t)));
    }

    public function store(Request $request)
    {
       

        // Basic guard (you said skip dedicated FormRequest)
        $request->validate([
            'type'        => 'required|in:support,feedback,return',
            'subject'     => 'required|string|min:4|max:200',
            'description' => 'required|string|min:10',
            'order_id'    => 'nullable|integer|min:1',
        ]);

     
         $customer = Auth::user()?->customers;

        return DB::transaction(function () use ($request,$customer) {
            // If you have a CodeGenerator like your address flow, use it:
            // $ref = CodeGenerator::createCode('TKT', 'Support_Tickets_T', 'Ticket_Reference');
            $ref = 'TKT-' . Str::upper(Str::random(8));

            $ticket = SupportTicket::create([
                'Ticket_Reference' => $ref,
                'User_Id'          => Auth::id(),
                'Customer_Id'      => $customer->id,
                'Subject'          => $request->input('subject'),
                'Ticket_Type'      => $request->input('type'),
                'Order_Id'         => $request->input('order_id'),
                'Ticket_Status'    => 'open',
            ]);

            // First message from user
            SupportTicketMessage::create([
                'Ticket_Id'   => $ticket->id,
                'Sender_Type' => 'user',
                'Message_Body'=> $request->input('description'),
            ]);

            try {
                app(CustomerNotificationService::class)->notifyUser(
                    (int) Auth::id(),
                    'customer.ticket_created',
                    CustomerNotificationPayload::adminMessage(
                        title: 'Request received',
                        message: "We received {$ref}. Our support team will review it.",
                        url: "/account?tab=tickets&ticket={$ticket->id}",
                        meta: [
                            'ticket_id' => $ticket->id,
                            'ticket_reference' => $ref,
                        ],
                    ),
                );
            } catch (\Throwable $exception) {
                Log::error('Failed to create ticket receipt notification', [
                    'ticket_id' => $ticket->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Ticket created.',
                'data'    => $this->toApi($ticket),
            ], 201);
        });
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $ticket = SupportTicket::findOrFail($id);
        if (!$this->ownsTicket($ticket, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = $ticket->messages()->orderBy('id')->get();

        return response()->json([
            'ticket'   => $this->toApi($ticket),
            'messages' => $messages->map(fn($m) => $this->toApiMessage($m))->values(),
        ]);
    }

    public function reply(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $request->validate([
            'body' => 'required|string|min:1',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        if (!$this->ownsTicket($ticket, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($ticket->Ticket_Status === 'closed') {
            return response()->json(['message' => 'Ticket is closed'], 422);
        }

        $msg = SupportTicketMessage::create([
            'Ticket_Id'   => $ticket->id,
            'Sender_Type' => 'user',
            'Message_Body'=> $request->input('body'),
        ]);

        // Optional: bump status
        if ($ticket->Ticket_Status === 'pending') {
            $ticket->update(['Ticket_Status' => 'open']);
        }

        return response()->json([
            'message' => 'Reply added.',
            'data'    => $this->toApiMessage($msg),
        ]);
    }

    public function close(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $ticket = SupportTicket::findOrFail($id);
        if (!$this->ownsTicket($ticket, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ticket->update(['Ticket_Status' => 'closed']);

        return response()->json([
            'message' => 'Ticket closed.',
            'data'    => $this->toApi($ticket),
        ]);
    }

    // Optional: delete a ticket (e.g., only if closed)
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $ticket = SupportTicket::findOrFail($id);
        if (!$this->ownsTicket($ticket, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($ticket->Ticket_Status !== 'closed') {
            return response()->json(['message' => 'Only closed tickets can be deleted'], 422);
        }

        $ticket->delete();

        return response()->json(['message' => 'Ticket deleted.']);
    }
}
