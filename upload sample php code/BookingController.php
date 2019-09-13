<?php


namespace App\Http\Controllers\Booking;


use App;
use App\Helpers\MailgunMailer;
use App\Helpers\Sms;
use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Messages\MessageController;
use App\Http\Traits\NotificationsTraits;
use App\Models\Activities;
use App\Models\ActivityAddons;
use App\Models\ActivityAvailability;
use App\Models\ActivityRules;
use App\Models\Backpackers;
use App\Models\Bookings;
use App\Models\BookingTickets;
use App\Models\Otp;
use App\Models\User;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\CannotInsertRecord;
use League\Csv\Writer as CsvWriter;
use Mailgun\Mailgun;
use SendGrid\Mail\TypeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;


/**
 * Class BookingController
 * @package App\Http\Controllers\Booking
 * @author Ibrahim Lukman <khalifaibrahim.ib@gmail.com>
 */

class BookingController extends Controller
{
    use NotificationsTraits;

    protected $image_path = '/storage/app/images/tickets';


    /**
     * Add a new Booking
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function add(Request $request)
    {
        $booker = $request->auth->id;
//        $booker = 1;

//         Check if the booker is a pro member
        if ($request->auth->profile_type == 1)
            $details = $this->fetchActivity($request->input('activity'), $booker);
        else
            $details = Activities::where('uuid', $request->input('activity'))->first();


        if (!$details)
            return $this->jsonResponse(['message' => 'Activity does not exist'], 400);

// 			Validating form values
        $this->validate($request, [
            'activity' => 'required|exists:activities,uuid',
            'date' => 'required|date',
            'availability' => 'required|exists:activity_availability,uuid',
            'time' => 'required|min:3',
            'ticket_types' => 'required|array',
            'ticket_types.*.name' => 'required',
            'ticket_types.*.number' => 'integer',
            'total_price' => 'required',
            'ticket_type' => 'required|boolean',
            'users.*.name' => 'required|min:2',
            'users.*.email' => 'email',
            'users.*.add_ons.*.id' => ['exists:activity_addons,uuid'],
            'users.*.add_ons.*.quantity' => 'integer',
            'channel' => 'required',
        ]);

// 			assigning booking details into container
        $booking_data = [
            'uuid' => $uuid = Str::uuid(),
            'activity_id' => $details->id,
            'date' => $trip_date = Carbon::parse($request->input('date')),
            'time' => $trip_time = $request->input('time'),
            'availability_id' => ActivityAvailability::where('uuid', $request->input('availability'))->first()->id,
            'tickets' => $request->input('ticket_types'),
            'total_price' => $request->input('total_price'),
            'ticket_type' => $type = $request->input('ticket_type'),
            'total_group_members' => $request->input('total_group_members'),
            'created_by' => $booker,
            'channel' => $request->input('channel'),
        ];

	//	initialize contanier to receive member details with ticket details
        $receivers = [];

        DB::beginTransaction();
        $add_booking = Bookings::create($booking_data);

        if (!$add_booking)
            return $this->jsonResponse(['message' => 'Booking not be created'], 400);


        $users = json_decode(json_encode($request->input('users')));

        foreach ($users as $user) {
            $user = (object)$user;

            $ticket_no = $this->uniqueTicketNo();
            $qr_file_name = "qr_code_{$ticket_no}.png";
            $qr_content = "{$ticket_no} | {$user->name} | {$user->email}";
            $qr = $this->createQRCode($qr_file_name, $qr_content);

            $add_ons = [];

            foreach ($user->add_ons as $key => $add_on) {
                $add_ons[$key]['id'] = ActivityAddons::where('uuid', $add_on->id)->first()->id;
                $add_ons[$key]['quantity'] = $add_on->quantity;
                $add_ons[$key]['price'] = $add_on->price;
                $add_ons[$key]['name'] = $add_on->name;
            }


            $qr_codes[] = $qr;

            if (!Backpackers::where('phone_no', $user->phone)->first()) {
                $userCheck = $this->register($user->phone);
            } else {
                $userCheck = Backpackers::where('phone_no', $user->phone)->first();
            }
            $ticket = [
                'uuid' => $ticket_uuid = Str::uuid(),
                'booking_id' => Bookings::where('uuid', $uuid)->first()->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->phone ?? '',
                'add_ons' => json_encode($add_ons),
                'ticket_no' => $ticket_no,
                'qr_image' => $qr,
                'activity' => $details->id,
                'backpacker_id' => $userCheck->id,
                'type' => $request->input('total_group_members') ? 'group' : 'individual'
            ];

            // Try Creating the booking
            if (!BookingTickets::create($ticket)) {
                DB::rollBack();
                // Delete all the already uploaded QR CODE
                foreach ($qr_codes as $qrc)
                    @unlink(base_path($qrc));

                return $this->jsonResponse(['message' => 'Booking could not be created'], 400);
            }

// 				assigning member and ticket details to the container 
            $receivers[] = [
                'name' => ($type == 0) ? $user->name : "member of {$user->name}",
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'ticket' => $ticket_no,
                'ticket_uuid' => $ticket_uuid,
                'qr' => $qr
            ];
        }

        DB::commit();

        $this->jsonResponse(['data' => $add_booking->uuid, 'message' => 'Booking added successfully'], 200)->send();

        $activity_rules = ActivityRules::where('activity_id', $details->id)->first();

        $this->notifyBackpackers($receivers, [
            'activity' => $details->name,
            'cover' => $details->cover_photo_path,
            'provider' => $details->full_name,
            'meeting' => $details->meeting_location ?? '',
            'location' => $details->activity_location ?? '',
            'activity_req' => ($activity_rules) ? $activity_rules->requirements : '',
            'trip_date' => $trip_date,
            'trip_time' => $trip_time
        ]);

        $this->initiateMessage($details, array_column($receivers, 'ticket_uuid'));
    }

    /**
     * Generate Unique ticket number
     * @return string
     */
    protected function uniqueTicketNo()
    {
        $number = str_pad(mt_rand(1, 999) . substr(time(), -6) . mt_rand(1, 999), 12, mt_rand(1, 99999), STR_PAD_BOTH);

        if (BookingTickets::where('ticket_no', $number)->first())
            return $this->uniqueTicketNo();

        return $number;
    }

    /**
     * Create QR CODE
     * @param string $file_name
     * @param string $content
     * @return string
     */
    protected function createQRCode(string $file_name, string $content)
    {
        // Create the directory if it doesn't exist
        if (!is_dir(base_path($this->image_path)))
            mkdir(base_path($this->image_path), 0777, true);

        $renderer = new ImageRenderer(
            new RendererStyle(500),
            new ImagickImageBackEnd()
        );
        $writer = new Writer($renderer);
        $file_path = $this->image_path . '/' . $file_name;
        $writer->writeFile($content, base_path($file_path));
        return $file_path;
    }

    /**
     * Register an account
     * @param Request $request
     * @return bool
     * @throws \Illuminate\Validation\ValidationException
     */
    public function register($phone = '')
    {

        $data = [
            'uuid' => $uuid = Str::uuid(),
            'phone_no' => $phone
        ];

        DB::beginTransaction();

        if (!$User = Backpackers::create($data))
            return false;

        DB::commit();

        return $User;
    }

    /**
     * Notify backpackers of new booking
     * @param array $data
     * @param $extra
     * @throws \Exception
     */
    protected function notifyBackpackers(array $data, array $extra = [])
    {
        $url = env('BACKPACK_FRONT');
        $activity = $extra['activity'];
        foreach ($data as $user) {
            $phone = $user->phone ?? '';
//            // Send notifications to memberss phone
            if (strlen($phone) > 0) {
                Sms::send([
                    'message' => "You have a booking on $activity. Click on the link to view ticket {$url}/ticket/{$user['ticket']}",
                    'receiver' => $phone
                ]);
            }

            $from = "Backpack Bookings<booking@backpack.sa>";
            $subject = "Here is your ticket to join on {$activity}";

            $this->sendEmailNotification("BookingSuccessful", $user['email'], [
                "{{TICKET_URL}}" => "$url/ticket/" . $user['ticket'],
                "{{BOOKER}}" => $user['name'],
                "{{ACTIVITY_NAME}}" => $activity,
                "http://placehold.it/200x200" => $extra['cover'],
                "{{BOOKING_TIME}}" => date("d F Y h:ia"),
                "{{ACTIVITY_PROVIDER}}" => $extra['provider'],
                "{{DATE}}" => $extra['trip_date'],
                "{{TIME}}" => $extra['trip_time'],
                "{{ACTIVITY_REQUIREMENTS}}" => $extra['activity_req'],
                "{{MEETING_POINT}}" => $extra['meeting'],
                "{{LOCATION}}" => $extra['location']
            ], [
                'from' => $from,
                'subject' => $subject
            ]);
        }
    }


    /**
     * Initiate a new message for the user
     * @param Activities $activity
     * @param array $tickets
     * @throws ValidationException
     */
    protected function initiateMessage(Activities $activity, array $tickets)
    {
        if (empty($activity->welcome_message) OR !isset($activity->welcome_message)) return;

        foreach ($tickets as $ticket) {
            $request = new Request([
                'text' => $activity->welcome_message,
                'ticket' => $ticket,
                'clientType' => 1,
                'auth' => User::find($activity->created_by)
            ]);

            (new MessageController())->privateMessage($request);
        }
    }

    /**
     * List all Booking
     * @param Request $request
     * @return JsonResponse
     */
    public function getBooking(Request $request): JsonResponse
    {
        $limit = $request->get('limit') ?? 12;
        $activity = $request->get('activity');

        if ($request->get('activity') && $request->get('activity') !== 'all') {
            $details = $this->fetchActivity($request->get('activity'), $request->auth->id);
            $booking = Bookings::with(['userTickets'])->where('created_by', $request->auth->id)->where('activity_id', $details->id)
                ->select(['uuid', 'id', 'date', 'time', 'tickets', 'total_price', 'status', 'created_by', 'activity_id',
                    'created_at AS date_booked', 'total_group_members', 'ticket_type', 'channel'])
                ->orderBy('id', 'DESC')->paginate($limit);
        } else {
            $booking = Bookings::with(['userTickets'])->where('created_by', $request->auth->id)
                ->select(['uuid', 'id', 'date', 'time', 'tickets', 'total_price', 'status', 'created_by', 'activity_id',
                    'created_at AS date_booked', 'total_group_members', 'ticket_type', 'channel'])
                ->orderBy('id', 'DESC')->paginate($limit);
        }


        $data = (array)json_decode(json_encode($booking));
        return $this->jsonResponse($data, 200);
    }

    /**
     * List all Users
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllBooking(Request $request, $uuid): JsonResponse
    {

        $details = $this->fetchActivity($uuid, $request->auth->id);
        $date = ($request->get('date') !== 'All') ? Carbon::parse($request->get('date'))->format('Y-m-d') : 'All';
        $search = $request->get('search');
        $filter = $request->get('filter');


        switch ($filter) {
            case 'paid':
                $q = 'bookings.paid = ?';
                $conds = [1];
                break;
            case 'not_paid':
                $q = 'bookings.paid = ?';
                $conds = [0];
                break;
            case 'verified':
                $q = 'bookings.verify = ?';
                $conds = [1];
                break;

            case 'not_verified':
                $q = 'bookings.verify = ?';
                $conds = [0];
                break;

            case 'check_in':
                $q = 'booking_tickets.status = ?';
                $conds = [2];
                break;

            case 'not_check_in':
                $q = 'booking_tickets.status != ?';
                $conds = [2];
                break;

            case 'all':
                $q = 'bookings.paid = ? OR bookings.paid = ?';
                $conds = [1, 0];
                break;
        }

        if ($date !== 'All' && $search === 'all') {
            $booking = Bookings::with(['userTickets'])
                ->where('bookings.activity_id', $details->id)
                ->whereRaw($q, $conds)
                ->whereDate('date', $date)
                ->select(['bookings.uuid', 'bookings.date', 'bookings.time', 'bookings.tickets', 'bookings.total_price', 'bookings.status', 'bookings.created_by', 'bookings.activity_id', 'bookings.paid',
                    'bookings.id', 'bookings.created_at AS date_booked', 'bookings.total_group_members', 'bookings.ticket_type', 'bookings.channel'])
                ->join('booking_tickets', 'booking_tickets.booking_id', '=', 'bookings.id')

                ->orderBy('id', 'DESC')->get()->toArray();

            return $this->jsonResponse(['data' => $booking], 200);
        }


        if ($date !== 'All' && $search !== 'all') {
            $booking = Bookings::with(['userTickets'])
                ->where('bookings.activity_id', $details->id)
                ->whereRaw($q, $conds)
                ->whereDate('bookings.date', 'like', '%' . $date . '%')
                ->where('booking_tickets.name', 'like', '%' . $search . '%')
                ->orWhere('booking_tickets.ticket_no', 'like', '%' . $search . '%')
                ->select(['bookings.uuid', 'bookings.date', 'bookings.time', 'bookings.tickets', 'bookings.total_price', 'bookings.status', 'bookings.created_by', 'bookings.activity_id', 'bookings.paid',
                    'bookings.id', 'bookings.created_at AS date_booked', 'bookings.total_group_members', 'bookings.ticket_type', 'bookings.channel'])
                ->join('booking_tickets', 'booking_tickets.booking_id', '=', 'bookings.id')
                ->orderBy('bookings.id', 'DESC')->get()->toArray();

            return $this->jsonResponse(['data' => $booking], 200);
        }






        if ($date === 'All' && $search !== 'all') {
            $booking = Bookings::with(['userTickets'])
                ->where('bookings.activity_id', $details->id)
                ->whereRaw($q, $conds)
                ->where('booking_tickets.name', 'like', '%' . $search . '%')
                ->orWhere('booking_tickets.ticket_no', 'like', '%' . $search . '%')
                ->select(['bookings.uuid', 'bookings.date', 'bookings.time', 'bookings.tickets', 'bookings.total_price', 'bookings.status', 'bookings.created_by', 'bookings.activity_id', 'bookings.paid',
                    'bookings.id', 'bookings.created_at AS date_booked', 'bookings.total_group_members', 'bookings.ticket_type', 'bookings.channel'])
                ->join('booking_tickets', 'booking_tickets.booking_id', '=', 'bookings.id')
                ->orderBy('bookings.id', 'DESC')->get()->toArray();

            return $this->jsonResponse(['data' => $booking], 200);
        }

        $booking = Bookings::with(['userTickets'])->where('activity_id', $details->id)
            ->whereRaw($q, $conds)
            ->select(['uuid', 'date', 'time', 'tickets', 'total_price', 'status', 'created_by', 'activity_id', 'paid',
                'id', 'created_at AS date_booked', 'total_group_members', 'ticket_type', 'channel'])
            ->join('booking_tickets', 'booking_tickets.booking_id', '=', 'bookings.id')
            ->orderBy('date', 'ASC')->get()->toArray();

        return $this->jsonResponse(['data' => $booking], 200);
    }

    /**
     * Confirm a booking status to 1
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function confirmBooking(Request $request, $uuid): JsonResponse
    {
        $details = Bookings::whereRaw('uuid = ?', [$uuid])->first();


        if (!$details)
            return $this->jsonResponse(['message' => 'Booking not found'], 400);


        $details->status = 1;
        $details->confirm_date = Carbon::now();

        if (!$details->save())
            return $this->jsonResponse(['message' => 'Booking could not be confirmed'], 400);


        return $this->jsonResponse(['message' => 'Booking confirmed successfully'], 200);
    }

    /**
     * Make Booking paid
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function bookingPaid(Request $request, $uuid): JsonResponse
    {
        $details = Bookings::whereRaw('uuid = ?', [$uuid])->first();


        if (!$details)
            return $this->jsonResponse(['message' => 'Booking not found'], 400);


        $details->paid = 1;
        $details->paid_date = Carbon::now();

        if (!$details->save())
            return $this->jsonResponse(['message' => 'Booking could not be paid'], 400);


        return $this->jsonResponse(['message' => 'Booking paid successfully'], 200);
    }

    /**
     * Confirm a booking status to 1
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function cancelTicket(Request $request, $uuid): JsonResponse
    {
        $details = BookingTickets::whereRaw('uuid = ?', [$uuid])->first();


        if (!$details)
            return $this->jsonResponse(['message' => 'Ticket not found'], 400);


        $details->status = 4;

        if (!$details->save())
            return $this->jsonResponse(['message' => 'Ticket could not be cancelled'], 400);


        return $this->jsonResponse(['message' => 'Ticket cancelled successfully'], 200);
    }

    /**
     * @return JsonResponse
     */
    public function listActivityBookingByUpcoming(Request $request, $uuid): JsonResponse
    {
        if ($uuid === 'all') {

            $activities = $this->fetchUserActivities($request)->get();
            if (!$activities)
                return $this->jsonResponse(['message' => 'No does not exist'], 404);


            $availability = [];

            foreach ($activities as $activity) {

                $availble = ActivityAvailability::where('activity_id', $activity->id)
                    ->where('start_date', '>=', Carbon::now())
                    ->where('status', 1)
                    ->orderby('start_date', 'ASC')->get();

                if (!empty($availble)) {
                    $availability[] = $availble;
                }
            }
            return $this->jsonResponse(['data' => $availability], 200);
        } else {

            $details = $this->fetchActivity($uuid, $request->auth->id);

            if (!$details)
                return $this->jsonResponse(['message' => 'Activity does not exist'], 404);

            // Check if the user has added the availability for the same start date
            if (!($availability = ActivityAvailability::where('activity_id', $details->id)->where('start_date', '>=', Carbon::now())->where('status', 1)->orderby('start_date', 'ASC')->get()))
                return $this->jsonResponse(['message' => 'No Availability for this activity'], 400);

            return $this->jsonResponse(['data' => $availability], 200);
        }
    }

    /**
     * Fetch User activities
     * @param Request $request
     * @return mixed
     */
    public function fetchUserActivities(Request $request)
    {
        $filter = $request->get('filter') ?? 'all';
        $user = $request->auth->id;
        $email = $request->auth->email;

        switch ($filter) {
            case 'online':
                $q = 'status = ? AND created_by = ?';
                $conds = [1, $user];
                break;
            case 'offline':
                $q = 'status = ? AND created_by = ?';
                $conds = [0, $user];
                break;
            case 'all':
            case 'available':
                $q = 'status <> ? AND created_by = ?';
                $conds = ['-1', $user];
                break;
        }

        $result = Activities::leftJoin('activity_organizers AS ao', 'activities.id', '=', 'ao.activity_id')
            ->whereRaw($q, $conds)
            ->orWhereRaw('ao.email = ?', $email)
            ->select(['activities.name', 'activities.uuid', 'description', 'activities.created_at',
                'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status'])
            ->orderBy('id', 'DESC');

        return $result;
    }

    /**
     * @return JsonResponse
     */
    public function listBookingByUpcoming(Request $request): JsonResponse
    {
        $limit = $request->get('limit') ?? 12;
        $activities = $this->fetchUserActivities($request)->get();
        if (!$activities)
            return $this->jsonResponse(['message' => 'No does not exist'], 404);


        $availability = [];

        foreach ($activities as $activity) {

            $availble = ActivityAvailability::where('activity_id', $activity->id)
                ->where('start_date', '>=', Carbon::now())
                ->where('status', 1)
                ->orderby('start_date', 'ASC')->get();

            if (!empty($availble)) {
                $availability[] = $availble;
            }

        }

        return $this->jsonResponse(['data' => $availability], 200);
    }

    /**
     * Get booking Details
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function bookingDetails(Request $request, $uuid): JsonResponse
    {
        $booking = Bookings::where('uuid', $uuid)->first();

        if (!$booking)
            return $this->jsonResponse(['message' => 'Booking does not exist'], 404);

        $today = Carbon::now();
        $other = Carbon::parse($booking->date);

        ($other->diffInHours($today) <= 24) ? $booking['checkIn'] = true : $booking['checkIn'] = false;


        return $this->jsonResponse(['data' => $booking], 200);
    }

    public function checkIn(Request $request, $uuid): JsonResponse
    {
        $details = BookingTickets::whereRaw('uuid = ?', [$uuid])->first();


        if (!$details)
            return $this->jsonResponse(['message' => 'Ticket not found'], 400);


        $details->status = 2;

        if (!$details->save())
            return $this->jsonResponse(['message' => 'Ticket could not be cancelled'], 400);


        return $this->jsonResponse(['message' => 'Ticket Checked In Successfully'], 200);
    }

    /**
     * Download Booking list a listing.
     *
     * @param Request $request
     * @param string $type
     * @return JsonResponse
     * @throws CannotInsertRecord
     * @throws TypeException
     */
    public function downloadExcel(Request $request, $type = 'csv', $uuid)
    {
        $date = ($request->get('date') !== 'All') ? Carbon::parse($request->get('date'))->format('Y-m-d') : 'All';
        $details = $this->fetchActivity($uuid, $request->auth->id);

        if ($date !== 'All') {
            $bookings = Bookings::where('created_by', $request->auth->id)
                ->where('activity_id', $details->id)
                ->whereDate('date', 'like', '%' . $date . '%')
                ->select(['id', 'date', 'time', 'total_price', 'created_at AS date_booked', 'ticket_type', 'channel', 'created_by', 'activity_id'])
                ->orderBy('id', 'DESC')->get();
            $ticket = [];

            foreach ($bookings as $booking) {
                $ticket[] = BookingTickets::select(['name', 'mobile', 'email', 'ticket_no', 'qr_image', 'booking_id'])->where('booking_id', $booking->id)->get()->toArray();
            }


            $arr = [];
            foreach ($ticket as $row) {
                $arr[] = $row[0];
            }

            $header = ['Name', 'Mobile', 'Email', 'Ticket No', 'QR Link', 'Start Date'];
            $records = $arr;

            $export = CsvWriter::createFromString();
            $export->insertOne($header);
            $export->insertAll($records);
            $file = $export->getContent();

            $title = 'Bookings for ' . $details->name;

            if (!$this->sendCsvToUser($title, $file, User::find($request->auth->id)->email))
                return $this->jsonResponse(['message' => 'FIle export was not successful'], 400);

            return $this->jsonResponse(['message' => 'List successfully exported to your email'], 200);

        } else {

            $bookings = Bookings::where('created_by', $request->auth->id)
                ->where('activity_id', $details->id)
                ->select(['id', 'date', 'time', 'total_price', 'created_at AS date_booked', 'ticket_type', 'channel', 'created_by', 'activity_id'])
                ->orderBy('id', 'DESC')->get();
            $ticket = [];

            foreach ($bookings as $booking) {
                $ticket[] = BookingTickets::select(['name', 'mobile', 'email', 'ticket_no', 'qr_image', 'booking_id'])->where('booking_id', $booking->id)->get()->toArray();
            }


            $arr = [];
            foreach ($ticket as $row) {
                $arr[] = $row[0];
            }

            $header = ['Name', 'Mobile', 'Email', 'Ticket No', 'QR Link', 'Start Date'];
            $records = $arr;

            $export = CsvWriter::createFromString();
            $export->insertOne($header);
            $export->insertAll($records);
            $file = $export->getContent();

            $title = 'Bookings for ' . $details->name;

            if (!$this->sendCsvToUser($title, $file, User::find($request->auth->id)->email))
                return $this->jsonResponse(['message' => 'FIle export was not successful'], 400);

            return $this->jsonResponse(['message' => 'List successfully exported to your email'], 200);
        }
    }

    /**
     * SEnd Csv to user mail
     * @param string $title
     * @param $file
     * @param $email
     * @return bool
     */
    protected function sendCsvToUser($title = '', $file, $email): bool
    {

        $attachment = MailgunMailer::prepareAttachmentFromMemory([
            ['name' => $title . ' CSV exported at ' . date('F jS, Y') . '.csv',
                'content' => $file]
        ]);

        try {
            MailgunMailer::send([
                'to' => $email . "<" . $email . ">",
                'subject' => "Export of $title",
                'attachment' => $attachment,
                'text' => "Your $title export was successful."
            ]);
            return true;
        } catch (Throwable $error) {
            Log::emergency($error);
            Log::alert($error);
            Log::critical($error);
            Log::error($error);
            Log::warning($error);
            Log::notice($error);
            Log::info($error);
            Log::debug($error);
            return false;
        }

    }

    /**
     * Get backpacker Activity
     * @param Request $request
     * @return JsonResponse
     */
    public function getBackpacerActivity(Request $request): JsonResponse
    {
        $user = $request->auth->id;

        $ticket = BookingTickets::where('backpacker_id', $user)->get();

        return $this->jsonResponse(['data' => $ticket], 200);
    }

    /**
     * Get Ticket Details
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function TicketDetails(Request $request, $uuid): JsonResponse
    {
        $booking = BookingTickets::with(['booking'])->where('uuid', $uuid)->first();

        if (!$booking)
            return $this->jsonResponse(['message' => 'Booking does not exist'], 404);

        $today = Carbon::now();
        $other = Carbon::parse($booking->date);

        ($other->diffInHours($today) <= 24) ? $booking['checkIn'] = true : $booking['checkIn'] = false;


        return $this->jsonResponse(['data' => $booking], 200);
    }


    /**
     * Fetch an Activity
     * @param $uuid
     * @param $creator
     * @return mixed
     */
    protected function fetchActivity($uuid, $creator)
    {
        return Activities::whereRaw('uuid = ? AND created_by = ?', [$uuid, $creator])->first();
    }

}
