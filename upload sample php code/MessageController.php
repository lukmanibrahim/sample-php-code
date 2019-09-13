<?php

namespace App\Http\Controllers\Messages;


use App\Helpers\Helpers;
use App\Helpers\MailgunMailer;
use App\Helpers\Sms;
use App\Http\Controllers\Controller;
use App\Models\Activities;
use App\Models\Backpackers;
use App\Models\BaseModel;
use App\Models\BookingTickets;
use App\Models\MessageGroups;
use App\Models\MessageReplies;
use App\Models\Messages;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Unirest\Request\Body;
use App\Models\Admin\AdminNotification;

/**
 * Class MessageController
 * @package App\Http\Controllers\Messages
 * @author Ibrahim Lukman <khalifaibrahim.ib@gmail.com>
 */
class MessageController extends Controller
{
    /**
     * @var string
     */
    private $image_path = '/messages/attachments';

    /**
     * Start a Message
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function start(Request $request): JsonResponse
    {
        $this->validate($request, [
            'activity' => 'required|exists:activities,uuid',
            'text' => 'required_without:attachment|nullable',
            'attachment' => 'nullable|mimes:jpeg,png,pdf,docx,doc,csv,xsls',
            'users.*' => 'exists:backpackers,uuid',
            'users' => 'nullable|array',
            'filters' => 'nullable',
            'trip_date' => 'nullable',
            'group' => 'nullable|exists:message_groups,uuid'
        ]);
		
		 
        $user = $request->auth->id;
        $activity = Activities::with(['tickets'])->where('uuid', $uuid = $request->input('activity'))->first();
        $tickets = $activity->tickets;
        $is_owner = (bool)($activity->created_by === $user && $request->clientType === 1);
        $attachment = $this->uploadAttachment($request);


		
        if ($tickets->count() === 0)
            return $this->respondWithError($is_owner ? "No one has booked this activity"
                . " yet, hence message cannot be sent." : "You cannot message the activity owner", $is_owner ? 400 : 403);

        $tickets_collection = $tickets->toArray();

        // If the current user is not the owner of the Activity
        // Check if they have a ticket
        if (!$is_owner) {
            $phones = array_unique(array_column($tickets_collection, 'mobile'));
            $emails = array_unique(array_column($tickets_collection, 'email'));

            // Check if the user got a ticket
            if (!in_array($request->auth->email, $emails) && !in_array($request->auth->phone_no, $phones))
                return $this->respondWithError('You cannot message the activity owner', 403);

            // Check if the user has a conversation already with the Activity owner for this Activity
            if (($message = Messages::whereRaw('activity_id = ? AND pro = ? AND backpacker = ?', [$activity->id, $activity->created_by,
                    $request->auth->id]))->count() > 0) {
                $reply = [
                    'uuid' => $uuid = (string)Str::uuid(),
                    'message_id' => $message->first()->id,
                    'sent_by' => 0, // If the current user is a backpacker
                    'text' => $text = $request->input('text'),
                    'attachment' => $attachment ?? '',
                    'status' => 0
                ];

                if (!MessageReplies::create($reply))
                    return $this->respondWithError('Message could not be sent.');

                $data = MessageReplies::where('uuid', $uuid)->first()->toArray();

                // Make the message live
                $this->fireToMessenger($request, $data, 'reply');

                // Notify the user/receiver
                $this->notifyUser($activity->created_by, [
                    // Email Phone Text and type
                    'type' => 'reply',
                    'text' => $text,
                    'message_uuid' => $uuid,
                    'activity' => $activity->name,
                    'sender_name' => Backpackers::find($user)->full_name,
                    'name' => User::find($activity->created_by)->full_name
                ]);

                return $this->respondWithSuccess('Message sent successfully');
            }

            // If all goes well insert the message
            $data = [
                'uuid' => $uuid = (string)Str::uuid(),
                'activity_id' => $activity->id,
                'backpacker' => $request->auth->id,
                'pro' => $activity->created_by,
                'started_by' => 0, // started by Backpacker
                'text' => $text = $request->input('text', ''),
                'attachment' => $attachment ?? '',
                'status' => 0
            ];

            if (!Messages::create($data))
                return $this->respondWithError('Message could not be sent, please try again!');


            $data = Messages::where('uuid', $uuid)->first()->toArray();

            // Make the message live
            $this->fireToMessenger($request, $data, 'new');

            $this->notifyUser($activity->created_by, [
                // Email Phone Text and type
                'type' => 'new',
                'text' => $text,
                'attachment' => $attachment ?? '',
                'message_uuid' => $uuid,
                'activity' => $activity->name,
                'name' => Backpackers::find($user)->full_name,
                'sender_name' => User::find($activity->created_by)->full_name
            ]);

            return $this->respondWithSuccess(['message' => 'Message sent successfully', 'uuid' => $uuid]);
        }


        // Let's roll when the activity owner is in charge
        DB::beginTransaction();

        $sent = $replies = $targets = [];
        $target_users = $request->input('users', []);

        if (count($target_users) > 0) {
            $target_users = Backpackers::whereIn('uuid', $target_users)->get(['id'])->pluck('id')->toArray();
            $tickets = BookingTickets::where('activity_id', $activity->id)->whereIn('backpacker_id', $target_users)->get();
        }

        // If parent group was sent
        $parent_group = ($group = $request->input('group', false))
            ? MessageGroups::where('uuid', $group)->first() : false;

        // Instantiate a new group for the user
        $group = MessageGroups::create([
            'filters' => $request->input('filters', ''),
            'text' => $text = $request->input('text'),
            'attachment' => $attachment,
            'uuid' => (string)Str::uuid(),
            'trip_date' => $request->input('trip_date', null),
            'created_by' => $activity->created_by,
            'parent' => ($parent_group) ? $parent_group->id : null
        ]);

        // Broadcast a Message to all activity ticket holders/backpackers
        foreach ($tickets as $ticket) {
            // Fetch the user data
            $user = Backpackers::find($ticket->backpacker_id);

            // If the User does not exist
            if (!$user || in_array($user->id, $targets)) continue;

            $targets[] = $user->id;


            // If the user has an open conversation
            // Send a reply
            if (($message = Messages::whereRaw('activity_id = ? AND pro = ? AND backpacker = ?',
                    [$activity->id, $activity->created_by, $user->id]))->count() > 0) {
                $reply = [
                    'uuid' => $uuid = (string)Str::uuid(),
                    'message_id' => $message->first()->id,
                    'sent_by' => 1,
                    'text' => $request->input('text'),
                    'attachment' => $attachment ?? '',
                    'status' => 0
                ];

                if (!MessageReplies::create($reply)) {
                    DB::rollBack();
                    return $this->respondWithError('Reply could not be sent.');
                }

                $replies[] = [
                    'message' => $uuid,
                    'email' => $ticket->email,
                    'phone' => $ticket->phone,
                    'user' => $backpacker = $ticket->backpacker_id,
                    'name' => Backpackers::find($backpacker)->full_name,
                    'sender_name' => User::find($activity->created_by)->full_name
                ];
                continue;
            }

            if ($message->count() === 0) {
                // If all goes well insert the message
                $data = [
                    'uuid' => $uuid = (string)Str::uuid(),
                    'activity_id' => $activity->id,
                    'backpacker' => $user->id,
                    'pro' => $activity->created_by,
                    'started_by' => 1, // started by Pro
                    'text' => $request->input('text', ''),
                    'attachment' => $attachment ?? '',
                    'group_id' => $group->id,
                    'status' => 0
                ];

                if (!Messages::create($data)) {
                    DB::rollBack();
                    return $this->respondWithError('Message could not be sent, please try again!');
                }

                $sent[] = [
                    'message' => $uuid,
                    'email' => $ticket->email,
                    'phone' => $ticket->phone,
                    'user' => $backpacker = $ticket->backpacker_id,
                    'name' => Backpackers::find($backpacker)->full_name,
                    'sender_name' => User::find($activity->created_by)->full_name
                ];
            }
        }

        DB::commit();

        foreach ($sent as $to) {
            $data = Messages::where('uuid', $to['message'])->first()->toArray();
            // Make the message live
            $this->fireToMessenger($request, $data, 'new');
            // Notify the user/receiver
            $this->notifyUser($to['user'], [
                // Email Phone Text and type
                'type' => 'new',
                'email' => $to['email'],
                'phone' => $to['phone'],
                'name' => $to['name'],
                'sender_name' => $to['sender_name'],
                'text' => $text,
                'message_uuid' => $to['message'],
                'activity' => $activity->name
            ], false);
        }

        foreach ($replies as $reply) {
            $data = MessageReplies::where('uuid', $reply['message'])->first()->toArray();
            // Make the message live
            $this->fireToMessenger($request, $data, 'reply');
            // Notify the user/receiver
            $this->notifyUser($reply['user'], [
                // Email Phone Text and type
                'type' => 'reply',
                'email' => $reply['email'],
                'phone' => $reply['phone'],
                'name' => $reply['name'],
                'sender_name' => $reply['sender_name'],
                'text' => $text,
                'message_uuid' => $reply['message'],
                'activity' => $activity->name
            ], false);
        }

        $total_sent = count($sent) + count($replies);
        return $this->respondWithSuccess([
            'message' => $total_sent > 0 ? "Message sent to {$total_sent} user(s)" : "Message sent successfully",
            'uuid' => $parent_group ? $parent_group->uuid : $group->uuid
        ]);
    }

    /**
     * Upload attachment
     * @param Request $request
     * @return array|\Illuminate\Http\UploadedFile|\Illuminate\Http\UploadedFile[]|string|null
     */
    protected function uploadAttachment(Request $request)
    {
        $attachment_sent = $request->hasFile('attachment') && $request->file('attachment')->isValid();
        // If any file was sent
        if (!$attachment_sent) return [];

        $attachment = $request->file('attachment');
        $file_name = basename($attachment->getClientOriginalName(), $attachment->clientExtension());
        $attachment_name = Str::slug($file_name . " " . date('F jS, Y'). " ".
                bin2hex(openssl_random_pseudo_bytes(12))) . '.' . $attachment->clientExtension();


        // Try uploading the File
        try {
            // Upload the image
            $image = $request->file('attachment')->storeAs(
                $this->image_path,
                $attachment_name,
                ['visibility' => 'public']
            );

            $attachment_url = $this->image_path . "/" . $attachment_name;
        } catch (\Throwable $t) {
            $this->jsonResponse(['message' => 'Attachment could not be sent, please try again!'], 400)->send();
            exit;
        }

        $attachment = [
            'name' => $attachment->getClientOriginalName(),
            'url' => env('AWS_CDN_URL') . $attachment_url,
            'size' => Helpers::bytesToHuman($attachment->getSize())
        ];

        return $attachment;
    }

    /**
     * Fire the Message to Messenger
     * @param Request $request
     * @param array $data
     * @param string $type
     */
    protected function fireToMessenger(Request $request, array $data, string $type): void
    {
        $url = env('MESSAGE_SERVER') . "/message/send";

        try {
            $data['type'] = $type;
            $payload = Body::Json($data);
            $headers = [
                'Authorization' => 'Bearer ' . $request->bearerToken(),
                'Content-Type' => 'application/json'
            ];
            \Unirest\Request::post($url, $headers, $payload);
        } catch (\Throwable $t) {
        }
    }

    /**
     * Notify user of new message
     * @param int $to
     * @param array $data
     * @param bool $pro
     * @throws \Exception
     */
    protected function notifyUser(int $to, array $data = [], bool $pro = true): void
    {
        $user = ($pro) ? User::find($to) : Backpackers::find($to);


        if (!$user) return;

        $email = $data['email'] ?? $user->email;
        $attachment = $data['attachment'] ?? false;
        $phone = $data['phone'] ?? $user->mobile_number;
        $sender = $data['sender_name'] ?? '';
        $receiver = $data['name'] ?? '';
        $message = $data['text'];
        $message_data = ($data['type'] === 'new') ?
            Messages::where('uuid', $data['message_uuid'])->first() :
            MessageReplies::where('uuid', $data['message_uuid'])->first();


        // if the email is set
        // Send an email notification
        if (strlen($email) > 0 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $html = $pro ? `
                Hello {$receiver},
                You have received a message from {$sender} regarding your booking at {$data['activity']}.
                <br><br>
                 <b>'{$message}'</b>
                 <br><br>
                 <a href="https://pro.backpack.sa">View Message</a>
                 <br>
                 Click on the link to view  the message and reply to it from your account`
                : `Hello {$receiver},
                    You have received a message from {$sender} regarding their booking on {$data['activity']}.
                    <br><br>
                     <b>'{$message}'</b>
                     <br><br>
                     <a href="https://backpack.sa">View Message</a>
                     <br>
                     Click on the link to view  the message and reply to it from your account`;

            // Send a mail to the User
            MailgunMailer::send([
                'from' => 'Backpack<no_reply@env' . env('MAILGUN_DOMAIN') . '>',
                'to' => $email,
                'subject' => "You've got a new message on Backpack",
                'html' => $html
            ]);

        }

        // Activate on Production
        // Send them a notification to their phone
        if (strlen($phone) > 0) {
            Sms::send([
                'message' => "You have received a message from $receiver regarding your activity {$data['activity']}",
                'receiver' => $phone
            ]);
        }

        // Create a notification
        Notification::create([
            'user_id' => ($pro) ? $user->id : null,
            'backpacker' => (!$pro) ? $user->id : null,
            'content' => `You have a new message on {$data['activity']}`,
            'uuid' => (string)Str::uuid(),
            'url' => '/messages/' . ($data['type'] === 'new') ? $message_data->uuid : $message_data->message_uuid,
            'status' => ($message_data && $message_data->status == 1) ? 1 : 0
        ]);
    }

    /**
     * Reply a message
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $message = Messages::where('uuid', $uuid)->first();
        $user = $request->auth->id;

        if (!$message OR ($message->backpacker != $user && $message->pro != $user))
            return $this->respondWithError('Message does not exist', 404);

        $this->validate($request, [
            'text' => 'required_without:attachment|nullable',
            'attachment' => 'nullable|mimes:jpeg,png,pdf,docx,doc,csv,xsls'
        ]);

        $attachment = $this->uploadAttachment($request);

        $data = [
            'uuid' => $uuid = (string)Str::uuid(),
            'message_id' => $message->id,
            'sent_by' => ($request->clientType == 1), // If the current user is a pro user set it to 1
            'text' => $text = $request->input('text'),
            'attachment' => $attachment,
            'status' => 0,
        ];

        if (!MessageReplies::create($data))
            return $this->respondWithError('Reply could not be sent.');

        $data = MessageReplies::where('uuid', $uuid)->first()->toArray();

        // Make the message live
        $this->fireToMessenger($request, $data, 'reply');

        // IF the Pro user sent the message contact the user
        if ($request->clientType == 1) {
            $backpacker = Backpackers::find($message->backpacker);
            // Notify the User
            $this->notifyUser($backpacker->id, [
                // Email Phone Text and type
                'type' => 'new',
                'email' => BookingTickets::where('activity_id', $message->activity_id)
                        ->where('backpacker_id', $backpacker->id)->first()->email ?? '',
                'phone' => $backpacker->mobile_number,
                'text' => $text,
                'message_uuid' => $message->uuid,
                'activity' => $message->subject
            ], false);
        } else {
            $pro = User::find($message->pro);
            // Notify the User
            $this->notifyUser($pro->id, [
                'type' => 'new',
                'text' => $text,
                'message_uuid' => $message->uuid,
                'activity' => $message->subject
            ]);
        }

        return $this->respondWithSuccess(['message'=>'Reply sent successfully', 'uuid'=>$message->uuid]);
    }


    /**
     * Mark message as Read
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */
//    public function markReplyRead(Request $request, string $uuid): JsonResponse
//    {
//        $reply = MessageReplies::where('uuid', $uuid)->first();
//        $user = $request->auth->id;
//
//        if (!$reply)
//            return $this->respondWithError('Reply not found', 404);
//
//        // If the current user is not the receiver
//        if ($user != $reply->receiver)
//            return $this->respondWithError('You cannot read this message', 403);
//
//        if (!$reply->update(['status' => 1]))
//            return $this->respondWithError('Message could not be read', 400);
//
//
//        return $this->respondWithSuccess('Message read successfully');
//    }

    /**
     * Send a Private message as a PRO user to a backpacker
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function privateMessage(Request $request): JsonResponse
    {
        $this->validate($request, [
            'ticket' => 'required|exists:booking_tickets,uuid',
            'text' => 'required_without:attachment|nullable',
            'attachment' => 'nullable|mimes:jpeg,png,pdf,docx,doc,csv,xsls',
        ]);

        $creator = $request->auth->id;

        // Fetch the ticket details
        $ticket = BookingTickets::with('booking')->where('uuid', $request->input('ticket'))->first();

        $user = Backpackers::find($ticket->backpacker_id);

        // If the User does not exist
        if (!$user)
            return $this->respondWithError("No Backpacker is tied to this ticket yet.", 400);

        $activity_id = $ticket->booking->first()->activity_id;
        $attachment = $this->uploadAttachment($request);
        // If a conversation is open before
        // Send in a reply
        if (($message = Messages::whereRaw('activity_id = ? AND pro = ? AND backpacker = ?', [$activity_id, $creator, $user->id]))->count() > 0) {
            $data = [
                'uuid' => $uuid = (string)Str::uuid(),
                'message_id' => $message->first()->id,
                'sent_by' => 1, // If the current user is a pro user
                'text' => $text = $request->input('text'),
                'attachment' => $attachment,
                'status' => 0
            ];
            $message = Messages::whereRaw('activity_id = ? AND pro = ? AND backpacker = ?', [$activity_id, $creator, $user->id])->first();

            if (!MessageReplies::create($data))
                return $this->respondWithError('Message could not be sent.');

            $data = MessageReplies::where('uuid', $uuid)->first()->toArray();

            // Make the message live
            $this->fireToMessenger($request, $data, 'reply');


            $this->notifyUser($ticket->backpacker_id, [
                // Email Phone Text and type
                'type' => 'reply',
                'email' => $ticket->email ?? '',
                'phone' => $ticket->mobile_number,
                'text' => $text,
                'message_uuid' => $uuid,
                'activity' => $message->first()->subject
            ], false);

            return $this->respondWithSuccess(['message'=>'Message sent successfully', 'uuid'=>$message->uuid]);
        }

        // Send a new message
        // If all goes well insert the message
        $data = [
            'uuid' => $uuid = (string)Str::uuid(),
            'activity_id' => $activity_id,
            'backpacker' => $user->id,
            'pro' => $creator,
            'started_by' => 0, // started by Backpacker
            'text' => $text = $request->input('text'),
            'status' => 0
        ];

        if (!Messages::create($data))
            return $this->respondWithError('Message could not be sent, please try again!');


        $data = Messages::where('uuid', $uuid)->first()->toArray();

        // Make the message live
        $this->fireToMessenger($request, $data, 'new');

        $this->notifyUser($ticket->backpacker_id, [
            // Email Phone Text and type
            'type' => 'new',
            'email' => $ticket->email ?? '',
            'phone' => $ticket->mobile_number,
            'text' => $text,
            'message_uuid' => $uuid,
            'activity' => $message->first()->subject
        ], false);

        return $this->respondWithSuccess('Message sent successfully');
    }

    /**
     * Mark Message as read
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function markMessagesRead(Request $request, string $uuid): JsonResponse
    {
        $message = Messages::where('uuid', $uuid)->first();
        $current_user = $request->auth->id;
        $user_data = ($request->clientType == 1) ? User::find($current_user) : Backpackers::find($current_user);

        $read = 0;

        if (!$message)
            return $this->respondWithError('Message not found.', 404);

        $replies = MessageReplies::whereRaw('message_id = ? AND status = ?', [$message->id, 0]);

        DB::beginTransaction();

        // if the message got unread replies
        if ($replies->count() > 0) {
            $replies = $replies->get();
            foreach ($replies as $reply) {
                // If the current user is the receiver
                if ($reply->receiver == $user_data->uuid) {
                    MessageReplies::find($reply->id)->update(['status' => 1]);
                    $read += 1;
                }
            }
        }

        DB::commit();

        return $this->respondWithSuccess($read > 0 ? "Message(s) read successfully." : "No message to mark as read");
    }

    /**
     * List Messages
     * @param Request $request
     * @return JsonResponse
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->auth->id;
        $per_page = $request->get('per_page') ?? 12;
        $messages = '';

        switch ($request->clientType) {
            case 1:
                $messages = Messages::where('pro', $user)->orderBy('id', 'desc');
                break;
            case 2:
                $messages = Messages::where('backpacker', $user)->orderBy('id', 'desc');
                break;
        }

        $messages = $messages->paginate($per_page)->toArray();
        return $this->respondWithSuccess(['data' => $messages]);
    }

    /**
     * List all messages broadcasts
     * @param Request $request
     * @return JsonResponse
     */
    public function listBroadcasts(Request $request): JsonResponse
    {
        $date = Carbon::now()->toDateTimeString();
        $user = $request->auth->id;

        $broadcasts = MessageGroups::where('created_by', $user)
                ->where('trip_date', ">=", $date)
                ->whereRaw('parent IS NULL')
                ->latest()->get()->toArray() ?? [];

        return $this->respondWithSuccess(['data' => $broadcasts]);
    }

    /**
     * Fetch Broadcast details
     * @param Request $request
     * @param string $broadcast
     * @return JsonResponse
     */
    public function broadcastDetails(Request $request, string $broadcast)
    {
        $details = MessageGroups::with(['groupReplies'])->where('uuid', $broadcast)
            ->where('created_by', $request->auth->id)
            ->first();

        if (!$details)
            return $this->respondWithError('Group message does not exist', 404);

        return $this->respondWithSuccess(['data' => $details->toArray()]);
    }

    /**
     * Load message details
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function details(Request $request, string $uuid): JsonResponse
    {
        $user = $request->auth->id;
        $user_data = ($request->clientType == 1) ? User::find($user) : Backpackers::find($user);
        $message = Messages::with(['replies'])->where('uuid', $uuid)->first();

        if (!$message OR ($message->pro != $user AND $message->backpacker != $user))
            return $this->respondWithError('Message does not exist', 404);

        // If the message is still unread and the current user didn't start th message
        // Mark it as read
        if (!$message->status && $message->initiator != $user_data->uuid)
            $message->update(['status' => 1]);


        $replies = '';

        // Also mark all replies that was not sent by the current user as read
        switch ($request->clientType) {
            case 1:
                $replies = MessageReplies::where([['message_id', $message->id], ['status', 0], ['sent_by', 0]]);
                break;
            case 2:
                $replies = MessageReplies::where([['message_id', $message->id], ['status', 0], ['sent_by', 1]]);
                break;
        }

        if ($replies->count() > 0)
            $replies->update(['status' => 1]);

        return $this->respondWithSuccess(['data' => $message->toArray()]);
    }

    /**
     * get admin notification for backpack users
     *
     * @param backpacker $uuid
     * @return jsonResponse $notification message
     */
    public function backpackAdminNotification($uuid)
    {
        $notification = AdminNotification::where('backpacker_uuid', $uuid)->orderBy('created_at', 'DESC')->get();

        if (!$notification) return $this->jsonResponse(['message' => 'no notification available'], 404);
        return $this->jsonResponse(['message' => 'success', 'notification' => $notification], 200);
    }

    /**
     * get admin notification for backpack users
     *
     * @param pro $uuid
     * @return jsonResponse $notification message
     */
    public function proAdminNotification($uuid)
    {
        $notification = AdminNotification::where('pro_uuid', $uuid)->orderBy('created_at', 'DESC')->get();

        if (!$notification) return $this->jsonResponse(['message' => 'no notification available'], 404);
        return $this->jsonResponse(['message' => 'success', 'notification' => $notification], 200);
    }

}
