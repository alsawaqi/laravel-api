<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
 protected $table = 'Support_Ticket_Messages_T';

    protected $fillable = [
        'Ticket_Id',
        'Sender_Type',
        'Message_Body',
    ];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'Ticket_Id');
    }
}
