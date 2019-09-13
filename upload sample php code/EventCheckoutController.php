<?php

namespace App\Http\Controllers;

use App\Events\OrderCompletedEvent;
use App\Models\Account;
use App\Models\AccountPaymentGateway;
use App\Models\Affiliate;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventStats;
use App\Models\GeaIssueTicket;
use App\Models\GeaShows;
use App\Models\GeaTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\PromoCode;
use App\Models\QuestionAnswer;
use App\Models\ReservedSeat;
use App\Models\ReservedTickets;
use App\Models\Ticket;
use App\MofaRequest;
use App\PayfortLog;
use App\Services\Order as OrderService;
use App\Services\Payfort;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use PhpSpec\Exception\Exception;
use DB;
use Cookie;
use Log;
use PDF;
use Omnipay;
use Validator;
use SoapClient;
use App\DateTicket;
use App\Models\MultipleEvent;
use App\Models\MultipleEventTicket;
use App\Models\MultipleReservedTickets;
use App\Models\MultipleEventStats;
use App\Models\OrganiserUsers;
use App\Models\SellerStats;

/**
 * Class EventCheckoutController
 * @package App\Http\Controllers\Event
 * @author Ibrahim Lukman <khalifaibrahim.ib@gmail.com>
 */

class EventCheckoutController extends Controller
{
    /**
     * Is the checkout in an embedded Iframe?
     *
     * @var bool
     */
    protected $is_embedded;

    /**
     * EventCheckoutController constructor.
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        /*
         * See if the checkout is being called from an embedded iframe.
         */
        $this->is_embedded = $request->get('is_embedded') == '1';
    }

    /**
     * Validate a ticket request. If successful reserve the tickets and redirect to checkout
     *
     * @param Request $request
     * @param $event_id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function postValidateTickets(Request $request, $event_id)
    {
        // return $request->all();
        /*
         * Order expires after X min
         */
        $order_expires_time = Carbon::now()->addMinutes(config('attendize.checkout_timeout_after'));

        $event = Event::findOrFail($event_id);

        if (!$request->has('tickets')) {
            session()->flash('message', 'No tickets selected.');

            return response()->json([
                'status'  => 'error',
                'message' => 'No tickets selected',
            ]);
        }

        $ticket_ids = json_decode($request->get('tickets'), true);

        if (empty($ticket_ids)) {
            session()->flash('message', 'No tickets selected.');
            return response()->json([
                'status'  => 'error',
                'message' => 'No tickets selected.',
            ]);
        }
        /*
         * Remove any tickets the user has reserved
         */
        ReservedTickets::where('session_id', '=', session()->getId())->delete();
        ReservedSeat::where('session_id', '=', session()->getId())->delete();

        /*
         * Go though the selected tickets and check if they're available
         * , tot up the price and reserve them to prevent over selling.
         */

        $validation_rules = [];
        $validation_messages = [];
        $tickets = [];
        $order_total = 0;
        $total_ticket_quantity = 0;
        $booking_fee = 0;
        $organiser_booking_fee = 0;
        $quantity_available_validation_rules = [];

        foreach ($ticket_ids as $ticket_id) {
            $current_ticket_quantity = (int)$request->get('ticket_' . $ticket_id);
            $seat[] = explode(",", $request->get('seat_' . $ticket_id));
            $letter[] = explode(",", $request->get('letter_' . $ticket_id));
            $tes = explode(",", $request->get('seat_' . $ticket_id));
            $tes2 = explode(",", $request->get('letter_' . $ticket_id));
            $merge = array_merge($seat);
            $merge_letter = array_merge($letter);

            if ($current_ticket_quantity < 1) {
                continue;
            }


            $total_ticket_quantity = $total_ticket_quantity + $current_ticket_quantity;

            $ticket = Ticket::find($ticket_id);

            $ticket_quantity_remaining = $ticket->quantity_remaining;


            $max_per_person = min($ticket_quantity_remaining, $ticket->max_per_person);

            $quantity_available_validation_rules['ticket_' . $ticket_id] = [
                'numeric',
                'min:' . $ticket->min_per_person,
                'max:' . $max_per_person
            ];

            $quantity_available_validation_messages = [
                'ticket_' . $ticket_id . '.max' => 'The maximum number of tickets you can register is ' . $ticket_quantity_remaining,
                'ticket_' . $ticket_id . '.min' => 'You must select at least ' . $ticket->min_per_person . ' tickets.',
            ];

            $validator = Validator::make(['ticket_' . $ticket_id => (int)$request->get('ticket_' . $ticket_id)],
                $quantity_available_validation_rules, $quantity_available_validation_messages);

            if ($validator->fails()) {
                return response()->json([
                    'status'   => 'error',
                    'messages' => $validator->messages()->toArray(),
                ]);
            }

            $order_total = $order_total + ($current_ticket_quantity * $ticket->price);
            $booking_fee = $booking_fee + ($current_ticket_quantity * $ticket->booking_fee);
            $organiser_booking_fee = $organiser_booking_fee + ($current_ticket_quantity * $ticket->organiser_booking_fee);

            $tickets[] = [
                'ticket'                => $ticket,
                'qty'                   => $current_ticket_quantity,
                'price'                 => ($current_ticket_quantity * $ticket->price),
                'booking_fee'           => ($current_ticket_quantity * $ticket->booking_fee),
                'organiser_booking_fee' => ($current_ticket_quantity * $ticket->organiser_booking_fee),
                'full_price'            => $ticket->price + $ticket->total_booking_fee,
                'seat'                  => $seat,
                'seat_sold'             => json_encode($tes, true),
                'merge'                 => array_pop($merge),
                'merge_letter'          => array_pop($merge_letter),
            ];

            /*
             * Reserve the tickets for X amount of minutes
             */

            foreach ($tes as $key => $value ) {
                Log::info($ticket_id . ' '. $event_id . ' '. $value);
                Log::info(ReservedSeat::where('ticket_id', $ticket_id)->where('event_id', $event_id)->where('seat', $value)->first());
                Log::info( 'Attend');
                Log::info( Attendee::where('ticket_id', $ticket_id)->where('event_id', $event_id)->where('seat', $value)->first());
                if ($value !== '0') {

                    if (ReservedSeat::where('ticket_id', $ticket_id)->where('event_id', $event_id)->where('seat', $value)->first()) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Seat ' . $value . ' is reserved',
                        ]);
                    }


                if(Attendee::where('ticket_id', $ticket_id)->where('event_id', $event_id)->where('seat', $value)->first()){
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Seat ' . $value . ' Not Available 1',
                    ]);
                }
                    $reservedSeat = new ReservedSeat();
                    $reservedSeat->ticket_id = $ticket_id;
                    $reservedSeat->event_id = $event_id;
                    $reservedSeat->seat = $value;
                    $reservedSeat->expires = $order_expires_time;
                    $reservedSeat->session_id = session()->getId();
                    $reservedSeat->save();
                }
            }



            $reservedTickets = new ReservedTickets();
            $reservedTickets->ticket_id = $ticket_id;
            $reservedTickets->event_id = $event_id;
            $reservedTickets->quantity_reserved = $current_ticket_quantity;
            $reservedTickets->expires = $order_expires_time;
            $reservedTickets->session_id = session()->getId();
            $reservedTickets->seats = $tes;
            $reservedTickets->seat_sold_letter = $tes2;
            $reservedTickets->save();

            for ($i = 0; $i < $current_ticket_quantity; $i++) {
                /*
                 * Create our validation rules here
                 */
                $validation_rules['ticket_holder_first_name.' . $i . '.' . $ticket_id] = ['required'];
                $validation_rules['ticket_holder_last_name.' . $i . '.' . $ticket_id] = ['required'];
                $validation_rules['ticket_holder_email.' . $i . '.' . $ticket_id] = ['required', 'email'];

                $validation_messages['ticket_holder_first_name.' . $i . '.' . $ticket_id . '.required'] = 'Ticket holder ' . ($i + 1) . '\'s first name is required';
                $validation_messages['ticket_holder_last_name.' . $i . '.' . $ticket_id . '.required'] = 'Ticket holder ' . ($i + 1) . '\'s last name is required';
                $validation_messages['ticket_holder_email.' . $i . '.' . $ticket_id . '.required'] = 'Ticket holder ' . ($i + 1) . '\'s email is required';
                $validation_messages['ticket_holder_email.' . $i . '.' . $ticket_id . '.email'] = 'Ticket holder ' . ($i + 1) . '\'s email appears to be invalid';
                /*
                 * Validation rules for custom questions
                 */
                foreach ($ticket->questions as $question) {

                    if ($question->is_required && $question->is_enabled) {
                        $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] = ['required'];
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.required'] = "This question is required";
                    }
                    if ($question->question_type_id == config('attendize.question_file')) {
                        $old = isset($validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id])
                            ? $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] : [];
                        $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] =
                            $old +
                            [
                                'file',
                                'mimes:pdf,jpeg,jpg,png',
                                'max:10000'
                            ];
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.file'] = "This answer must be a file";
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.mimes'] = "This file can only be PDF/PNG/JPEG";
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.max'] = "Size can't be larger than 10 MB";
                    }

                    if ($question->question_type_id == config('attendize.question_datetime')) {
                        $old = isset($validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id])
                            ? $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] : [];
                        $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] =
                            $old +
                            [
                                'date_format:Y-m-d',
                            ];
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.date_format'] = "Date/time format must be YYYY-MM-DD HH:mm";
                    }
                }
            }
        }

        if (empty($tickets)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No tickets selected.',
            ]);
        }

        if (config('attendize.enable_dummy_payment_gateway') == TRUE) {
            $activeAccountPaymentGateway = new AccountPaymentGateway();
            $activeAccountPaymentGateway->fill(['payment_gateway_id' => config('attendize.payment_gateway_dummy')]);
            $paymentGateway = $activeAccountPaymentGateway;
        } else {
            $activeAccountPaymentGateway = $event->account->active_payment_gateway ? $event->account->active_payment_gateway->firstOrFail() : false;
            $paymentGateway = $event->account->active_payment_gateway ? $event->account->active_payment_gateway->payment_gateway->firstOrFail() : false;
        }

        /*
         * The 'ticket_order_{event_id}' session stores everything we need to complete the transaction.
         */
        if($request->seller_slug) {
            session()->put('ticket_order_' . $event->id, [
                'validation_rules'        => $validation_rules,
                'validation_messages'     => $validation_messages,
                'event_id'                => $event->id,
                'tickets'                 => $tickets,
                'total_ticket_quantity'   => $total_ticket_quantity,
                'order_started'           => time(),
                'expires'                 => $order_expires_time,
                'reserved_tickets_id'     => $reservedTickets->id,
                'order_total'             => $order_total,
                'booking_fee'             => $booking_fee,
                'organiser_booking_fee'   => $organiser_booking_fee,
                'total_booking_fee'       => $booking_fee + $organiser_booking_fee,
                'order_requires_payment'  => false,
                'account_id'              => $event->account->id,
                'affiliate_id'            => Cookie::get('affiliate_' . $event_id),
                'seat'                    => '',
                'seller_slug'               => $request->seller_slug,
                // 'account_payment_gateway' => '',
                // 'payment_gateway'         => '',
            ]);
        } else {
            session()->put('ticket_order_' . $event->id, [
                'validation_rules'        => $validation_rules,
                'validation_messages'     => $validation_messages,
                'event_id'                => $event->id,
                'tickets'                 => $tickets,
                'total_ticket_quantity'   => $total_ticket_quantity,
                'order_started'           => time(),
                'expires'                 => $order_expires_time,
                'reserved_tickets_id'     => $reservedTickets->id,
                'order_total'             => $order_total,
                'booking_fee'             => $booking_fee,
                'organiser_booking_fee'   => $organiser_booking_fee,
                'total_booking_fee'       => $booking_fee + $organiser_booking_fee,
                'order_requires_payment'  => (ceil($order_total) == 0) ? false : true,
                'account_id'              => $event->account->id,
                'affiliate_id'            => Cookie::get('affiliate_' . $event_id),
                'account_payment_gateway' => $activeAccountPaymentGateway,
                'payment_gateway'         => $paymentGateway,
                'seat'                    => '',
                'seller_slug'                  => ''
            ]);
        }
        

        /*
         * If we're this far assume everything is OK and redirect them
         * to the the checkout page.
         */
        // try {
//            if ($request->ajax()) {
                if($request->seller_slug){
                    return response()->json([
                        'status'      => 'success',
                        'redirectUrl' => route('showEventCheckoutSeller', [
                                'event_id'    => $event_id,
                                'seller_slug' => $request->seller_slug,
                                'is_embedded' => $this->is_embedded,
                            ]) . '#order_form',
                    ]);
                } else {
                    return response()->json([
                        'status'      => 'success',
                        'redirectUrl' => route('showEventCheckout', [
                                'event_id'    => $event_id,
                                'is_embedded' => $this->is_embedded,
                            ]) . '#order_form',
                    ]);
                }
//            }
        // } 
        // catch(\Exception $e) {
        //     // return $e;
        //     return response()->json([
        //         'status' => 'error',
        //         'messages' => $e,
        //     ]);
        // }

        /*
         * Maybe display something prettier than this?
         */
        exit('Please enable Javascript in your browser.');
    }



    /**
     * Validate a ticket request. If successful reserve the tickets and redirect to checkout
     *
     * @param Request $request
     * @param $event_id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */


    public function postMultipleValidateTickets(Request $request)
    {

        $event_id = $request->event_id;
        $attend_date = $request->attend_date;

        /*
         * Order expires after X min
         */
        $order_expires_time = Carbon::now()->addMinutes(config('attendize.checkout_timeout_after'));

        $event = Event::findOrFail($event_id);

        if (!$request->has('tickets')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No tickets selected',
            ]);
        }

        $ticket_objs = $request->get('tickets');

        /*
         * Remove any tickets the user has reserved
         */
        ReservedTickets::where('session_id', '=', session()->getId())->delete();

        /*
         * Go though the selected tickets and check if they're available
         * , tot up the price and reserve them to prevent over selling.
         */
        $validation_rules = [];
        $validation_messages = [];
        $tickets = [];
        $order_total = 0;
        $total_ticket_quantity = 0;
        $booking_fee = 0;
        $organiser_booking_fee = 0;
        $quantity_available_validation_rules = [];

        foreach ($ticket_objs as $key => $ticket_obj) {

            $ticket_id = key($ticket_obj);

            $current_ticket_quantity = implode(":", $ticket_obj);
            
            if ($current_ticket_quantity < 1) {
                continue;
            }

            $total_ticket_quantity = $total_ticket_quantity + $current_ticket_quantity;

            $ticket = Ticket::find($ticket_id);

            // return MultipleEventTicket::all();

            $get_ticket_stat = MultipleEventTicket::where('event_id', $event_id)
                                                ->where(function($query) use ($attend_date, $ticket_id) {
                                                 $query->where('schedule_date', $attend_date)
                                                        ->where('ticket_id', $ticket_id);
                                                })
                                                ->first();
            // return $get_ticket_stat;

            $ticket_quantity_remaining = ($get_ticket_stat->quantity_available - $get_ticket_stat->quantity_sold);

            $max_per_person = min($ticket_quantity_remaining, $ticket->max_per_person);

            $quantity_available_validation_rules['ticket_' . $ticket_id] = [
                'numeric',
                'min:' . $ticket->min_per_person,
                'max:' . $max_per_person
            ];

            $quantity_available_validation_messages = [
                'max' => "The max number of ticket for" .  " '$ticket->title' is " .  $ticket_quantity_remaining,
                'ticket_' . $ticket_id . '.min' => 'You must select at least ' . $ticket->min_per_person . ' tickets.',
            ];
            //dumb
            $validator = Validator::make(['ticket_' . $ticket_id => $current_ticket_quantity],
                $quantity_available_validation_rules, $quantity_available_validation_messages);

            if ($validator->fails()) {
                return response()->json([
                    'status'   => 'error',
                    'messages' => $validator->messages()->toArray(),
                ]);
            }

            $order_total = $order_total + ($current_ticket_quantity * $ticket->price);
            $booking_fee = $booking_fee + ($current_ticket_quantity * $ticket->booking_fee);
            $organiser_booking_fee = $organiser_booking_fee + ($current_ticket_quantity * $ticket->organiser_booking_fee);

            $tickets[] = [
                'ticket'                => $ticket,
                'qty'                   => $current_ticket_quantity,
                'price'                 => ($current_ticket_quantity * $ticket->price),
                'booking_fee'           => ($current_ticket_quantity * $ticket->booking_fee),
                'organiser_booking_fee' => ($current_ticket_quantity * $ticket->organiser_booking_fee),
                'full_price'            => $ticket->price + $ticket->total_booking_fee,
            ];


            /*
             * Reserve the tickets for X amount of minutes
             */
            $multipleReservedTickets = new MultipleReservedTickets();
            $multipleReservedTickets->ticket_id = $ticket_id;
            $multipleReservedTickets->event_id = $event_id;
            // $multipleReservedTickets->schedule_date = $request->attend_date;
            
            $multipleReservedTickets->quantity_reserved = $current_ticket_quantity;
            $multipleReservedTickets->expires = $order_expires_time;
            $multipleReservedTickets->schedule_date = $request->attend_date;
            $multipleReservedTickets->session_id = session()->getId();
            $multipleReservedTickets->save();

            for ($i = 0; $i < $current_ticket_quantity; $i++) {
                /*
                 * Create our validation rules here
                 */
                $validation_rules['ticket_holder_first_name.' . $i . '.' . $ticket_id] = ['required'];
                $validation_rules['ticket_holder_last_name.' . $i . '.' . $ticket_id] = ['required'];
                $validation_rules['ticket_holder_email.' . $i . '.' . $ticket_id] = ['required', 'email'];

                $validation_messages['ticket_holder_first_name.' . $i . '.' . $ticket_id . '.required'] = 'Ticket holder ' . ($i + 1) . '\'s first name is required';
                $validation_messages['ticket_holder_last_name.' . $i . '.' . $ticket_id . '.required'] = 'Ticket holder ' . ($i + 1) . '\'s last name is required';
                $validation_messages['ticket_holder_email.' . $i . '.' . $ticket_id . '.required'] = 'Ticket holder ' . ($i + 1) . '\'s email is required';
                $validation_messages['ticket_holder_email.' . $i . '.' . $ticket_id . '.email'] = 'Ticket holder ' . ($i + 1) . '\'s email appears to be invalid';
                /*
                 * Validation rules for custom questions
                 */
                foreach ($ticket->questions as $question) {

                    if ($question->is_required && $question->is_enabled) {
                        $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] = ['required'];
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.required'] = "This question is required";
                    }
                    if ($question->question_type_id == config('attendize.question_file')) {
                        $old = isset($validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id])
                            ? $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] : [];
                        $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] =
                            $old +
                            [
                                'file',
                                'mimes:pdf,jpeg,jpg,png',
                                'max:10000'
                            ];
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.file'] = "This answer must be a file";
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.mimes'] = "This file can only be PDF/PNG/JPEG";
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.max'] = "Size can't be larger than 10 MB";
                    }

                    if ($question->question_type_id == config('attendize.question_datetime')) {
                        $old = isset($validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id])
                            ? $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] : [];
                        $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] =
                            $old +
                            [
                                'date_format:Y-m-d',
                            ];
                        $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.date_format'] = "Date/time format must be YYYY-MM-DD HH:mm";
                    }

                }

            }

        }

        if (empty($tickets)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No tickets selected.',
            ]);
        }

        if (config('attendize.enable_dummy_payment_gateway') == TRUE) {
            $activeAccountPaymentGateway = new AccountPaymentGateway();
            $activeAccountPaymentGateway->fill(['payment_gateway_id' => config('attendize.payment_gateway_dummy')]);
            $paymentGateway = $activeAccountPaymentGateway;
        } else {
            $activeAccountPaymentGateway = $event->account->active_payment_gateway ? $event->account->active_payment_gateway : false;
            $paymentGateway = $event->account->active_payment_gateway ? $event->account->active_payment_gateway->payment_gateway : false;
        }

        /*
         * The 'ticket_order_{event_id}' session stores everything we need to complete the transaction.
         */
        if($request->seller_slug){
            // $activeAccountPaymentGateway = new AccountPaymentGateway();
            // $activeAccountPaymentGateway->fill(['payment_gateway_id' => 0]);
            // $paymentGateway = $activeAccountPaymentGateway;
            session()->put('ticket_order_' . $event->id, [
                'validation_rules'        => $validation_rules,
                'validation_messages'     => $validation_messages,
                'event_id'                => $event->id,
                'tickets'                 => $tickets,
                'total_ticket_quantity'   => $total_ticket_quantity,
                'order_started'           => time(),
                'expires'                 => $order_expires_time,
                'reserved_tickets_id'     => $multipleReservedTickets->id,
                'order_total'             => $order_total,
                'booking_fee'             => $booking_fee,
                'organiser_booking_fee'   => $organiser_booking_fee,
                'total_booking_fee'       => $booking_fee + $organiser_booking_fee,
                'order_requires_payment'  => false,
                'account_id'              => $event->account->id,
                'affiliate_id'            => Cookie::get('affiliate_' . $event_id),
                'seller_slug'                  => $request->seller_slug,
         
            ]);
        } else {
            session()->put('ticket_order_' . $event->id, [
                'validation_rules'        => $validation_rules,
                'validation_messages'     => $validation_messages,
                'event_id'                => $event->id,
                'tickets'                 => $tickets,
                'total_ticket_quantity'   => $total_ticket_quantity,
                'order_started'           => time(),
                'expires'                 => $order_expires_time,
                'reserved_tickets_id'     => $multipleReservedTickets->id,
                'order_total'             => $order_total,
                'booking_fee'             => $booking_fee,
                'organiser_booking_fee'   => $organiser_booking_fee,
                'total_booking_fee'       => $booking_fee + $organiser_booking_fee,
                'order_requires_payment'  => (ceil($order_total) == 0) ? false : true,
                'account_id'              => $event->account->id,
                'affiliate_id'            => Cookie::get('affiliate_' . $event_id),
                'account_payment_gateway' => $activeAccountPaymentGateway,
                'payment_gateway'         => $paymentGateway,
                'seller_slug'                  => ''

            ]);
        }

        if ($request->ajax()) {
            if($request->seller_slug){
                return response()->json([
                    'status'      => 'success',
                    'redirectUrl' => route('showEventCheckoutSeller', [
                            'event_id'    => $event_id,
                            'seller_slug' => $request->seller_slug,
                            'is_embedded' => $this->is_embedded,
                            'attend_date' => $request->attend_date,
                        ]) . '#order_form',
                ]);
            } 
            else {
                return response()->json([
                    'status'      => 'success',
                    'redirectUrl' => route('showEventCheckout', [
                            'event_id'    => $event_id,
                            'is_embedded' => $this->is_embedded,
                            'attend_date' => $request->attend_date,
                        ]) . '#order_form',
                ]);
            }
        } 
        /*
         * Maybe display something prettier than this?
         */
        exit('Please enable Javascript in your browser.');
    }

    

    /**
     * Show the checkout page
     *
     * @param Request $request
     * @param $event_id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function showEventCheckout(Request $request, $event_id)
    {
        $order_session = session()->get('ticket_order_' . $event_id);

        // return $order_session;

        unset($order_session['coupon']);
        unset($order_session['became_free']);
        session()->put('ticket_order_' . $event_id, $order_session);
        if (!$order_session || $order_session['expires'] < Carbon::now()) {
            $route_name = $this->is_embedded ? 'showEmbeddedEventPage' : 'showEventPage';
            return redirect()->route($route_name, ['event_id' => $event_id]);
        }

        $secondsToExpire = Carbon::now()->diffInSeconds($order_session['expires']);

        $event = Event::findorFail($order_session['event_id']);

        $orderService = new OrderService($order_session['order_total'], $order_session['total_booking_fee'], $event);
        $orderService->calculateFinalCosts();

        $data = $order_session + [
                'event'           => $event,
                'secondsToExpire' => $secondsToExpire,
                'is_embedded'     => $this->is_embedded,
                'orderService'    => $orderService,
                'attend_date'     => $request->attend_date,
            ];

        if ($this->is_embedded) {
            return view('Public.ViewEvent.Embedded.EventPageCheckout', $data);
        }

        return view('Public.ViewEvent.EventPageCheckout', $data);
    }


    public function showEventCheckoutSeller(Request $request, $event_id, $seller_slug)
    {
        // return $request->all();

        $order_session = session()->get('ticket_order_' . $event_id);

        unset($order_session['coupon']);
        unset($order_session['became_free']);
        session()->put('ticket_order_' . $event_id, $order_session);
        if (!$order_session || $order_session['expires'] < Carbon::now()) {
            $route_name = 'showEventSellerPage';
            return redirect()->route($route_name, ['event_id' => $event_id, 'seller_slug' => $seller_slug]);
        }

        $secondsToExpire = Carbon::now()->diffInSeconds($order_session['expires']);

        $event = Event::findorFail($order_session['event_id']);
        // $seller = OrganiserUsers::findorFail($seller_slug);


        $orderService = new OrderService($order_session['order_total'], $order_session['total_booking_fee'], $event);
        $orderService->calculateFinalCosts();

        $data = $order_session + [
                'event'           => $event,
                'secondsToExpire' => $secondsToExpire,
                'is_embedded'     => $this->is_embedded,
                'orderService'    => $orderService,
                'attend_date'     => $request->attend_date,
                'seller_slug'       => $seller_slug
            ];

        return view('Public.ViewEvent.SellerPages.EventPageCheckout', $data);

    }

    /**
     * Create the order, handle payment, update stats, fire off email jobs then redirect user
     *
     * @param Request $request
     * @param $event_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function postCreateOrder(Request $request, $event_id)
    {

        // return $request->seller_slug;
        /*
         * If there's no session kill the request and redirect back to the event homepage.
         */
        if (!session()->get('ticket_order_' . $event_id)) {
            return response()->json([
                'status'      => 'error',
                'message'     => 'Your session has expired.',
                'redirectUrl' => route('showEventPage', [
                    'event_id' => $event_id,
                ])
            ]);
        }


        $event = Event::findOrFail($event_id);
        $order = new Order;
        $ticket_order = session()->get('ticket_order_' . $event_id);

        $validation_rules = $ticket_order['validation_rules'] ? $ticket_order['validation_rules'] : '';
        $validation_messages = $ticket_order['validation_messages'] ? $ticket_order['validation_messages'] : '';

        $order->rules = $order->rules + $validation_rules;
        $order->messages = $order->messages + $validation_messages;

        if (!$order->validate($request->all())) {
            return response()->json([
                'status'   => 'error',
                'messages' => $order->errors(),
            ]);
        }

        $request_data = $request->except(['card-number', 'card-cvc']);

        // return $request_data;


        $ticket_questions = $request->ticket_holder_questions ? $request->ticket_holder_questions : [];

        $ticket_number_for_date = 0;

        foreach ($ticket_order['tickets'] as $attendee_details) {
            for ($i = 0; $i < $attendee_details['qty']; $i++) {
                foreach ($attendee_details['ticket']->questions as $question) {
                    $ticket_answer = isset($ticket_questions[$attendee_details['ticket']->id][$i][$question->id]) ? $ticket_questions[$attendee_details['ticket']->id][$i][$question->id] : null;
                    if (is_object($ticket_answer) && get_class($ticket_answer) == 'Illuminate\Http\UploadedFile') {
                        $path = $ticket_answer->store('question_files');
                        $request_data['ticket_holder_questions'][$attendee_details['ticket']->id][$i][$question->id] = $path;
                    }
                }
            }
        }
        /*
         * Add the request data to a session in case payment is required off-site
         */
        session()->push('ticket_order_' . $event_id . '.request_data', $request_data);

        /*
         * Begin payment attempt before creating the attendees etc.
         * */
        if ($ticket_order['order_requires_payment'] && !isset($ticket_order['became_free'])) {

            return $this->handlePayment($request, $event, $event_id, $ticket_order);
        }
        /*
         * No payment required so go ahead and complete the order
         */

        return $this->completeOrder($event_id, $request->attend_date);
    }


    /**
     * Attempt to complete a user's payment when they return from
     * an off-site gateway
     *
     * @param Request $request
     * @param $event_id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function showEventCheckoutPaymentReturn(Request $request, $event_id)
    {
        if ($request->payfort_successful) {
            return $this->completeOrder($event_id, $attend_date = null, false);
        }

        if ($request->get('is_payment_cancelled') == '1') {
            session()->flash('message', 'You cancelled your payment. You may try again.');
            return response()->redirectToRoute('showEventCheckout', [
                'event_id'             => $event_id,
                'is_payment_cancelled' => 1,
            ]);
        }

        $ticket_order = session()->get('ticket_order_' . $event_id);
        $gateway = Omnipay::create($ticket_order['payment_gateway']->name);

        $gateway->initialize($ticket_order['account_payment_gateway']->config + [
                'testMode' => config('attendize.enable_test_payments'),
            ]);

        $transaction = $gateway->completePurchase($ticket_order['transaction_data'][0]);

        $response = $transaction->send();

        if ($response->isSuccessful()) {
            session()->push('ticket_order_' . $event_id . '.transaction_id', $response->getTransactionReference());
            return $this->completeOrder($event_id, $attend_date = null, false);
        } else {
            session()->flash('message', $response->getMessage());
            return response()->redirectToRoute('showEventCheckout', [
                'event_id'          => $event_id,
                'is_payment_failed' => 1,
            ]);
        }

    }


    public function showEventCheckoutPaymentReturnMultiple(Request $request, $event_id, $attend_date)
    {
        if ($request->payfort_successful) {
            return $this->completeOrder($event_id, $attend_date, false);
        }

        if ($request->get('is_payment_cancelled') == '1') {
            session()->flash('message', 'You cancelled your payment. You may try again.');
            return response()->redirectToRoute('showEventCheckout', [
                'event_id'             => $event_id,
                'attend_date'          => $attend_date,
                'is_payment_cancelled' => 1,
            ]);
        }

        $ticket_order = session()->get('ticket_order_' . $event_id);
        $gateway = Omnipay::create($ticket_order['payment_gateway']->name);

        $gateway->initialize($ticket_order['account_payment_gateway']->config + [
                'testMode' => config('attendize.enable_test_payments'),
            ]);

        $transaction = $gateway->completePurchase($ticket_order['transaction_data'][0]);

        $response = $transaction->send();

        if ($response->isSuccessful()) {
            session()->push('ticket_order_' . $event_id . '.transaction_id', $response->getTransactionReference());
            return $this->completeOrder($event_id, $attend_date,  false);
        } else { 
            session()->flash('message', $response->getMessage());
            return response()->redirectToRoute('showEventCheckout', [
                'event_id'          => $event_id,
                'attend_date'          => $attend_date,
                'is_payment_failed' => 1,
            ]);
        }

    }

    /**
     * Complete an order
     *
     * @param $event_id
     * @param bool|true $return_json
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function completeOrder($event_id, $attend_date, $return_json = true)
    {


        Log::info("this is the schedule date: ");
        Log::info($attend_date);

        $attend_order_date = date("Y-m-d", strtotime($attend_date));

        DB::beginTransaction();

        try {

            $order = new Order();
            $ticket_order = session()->get('ticket_order_' . $event_id);
            $request_data = $ticket_order['request_data'][0];

            $event = Event::findOrFail($ticket_order['event_id']);
            $attendee_increment = 1;
            $ticket_questions = isset($request_data['ticket_holder_questions']) ? $request_data['ticket_holder_questions'] : [];
            /*
             * Create the order
             */
            if (isset($ticket_order['transaction_id'])) {
                $order->transaction_id = $ticket_order['transaction_id'][0];
            }
            if ($ticket_order['order_requires_payment'] && !isset($request_data['pay_offline']) && !isset($ticket_order['became_free'])) {
                $order->payment_gateway_id = $ticket_order['payment_gateway']->id;
            }
            $order->attend_date = $attend_order_date;
            $order->first_name = strip_tags($request_data['order_first_name']);
            $order->last_name = strip_tags($request_data['order_last_name']);
            $order->email = $request_data['order_email'];
            $order->mobile = $request_data['order_mobile'];
            $order->order_status_id = isset($request_data['pay_offline']) ? config('attendize.order_awaiting_payment') : config('attendize.order_complete');
            $order->amount = $ticket_order['order_total'];
            $order->booking_fee = $ticket_order['booking_fee'];
            $order->organiser_booking_fee = $ticket_order['organiser_booking_fee'];
            $order->discount = isset($ticket_order['coupon']) ? $ticket_order['coupon']['discount_amount'] : 0.00;
            $order->promo_code_id = isset($ticket_order['coupon']) ? $ticket_order['coupon']['id'] : null;
            $order->account_id = $event->account->id;
            $order->event_id = $ticket_order['event_id'];
            $order->is_payment_received = isset($request_data['pay_offline']) ? 0 : 1;
            $order->transaction_ref = isset($ticket_order['transaction_ref']) ? $ticket_order['transaction_ref'] : null;
            // Calculating grand total including tax
            $orderService = new OrderService($ticket_order['order_total'], $ticket_order['total_booking_fee'], $event, $order->discount);

            $orderService->calculateFinalCosts();

            $order->taxamt = $orderService->getTaxAmount();
            $order->save();

            if(!empty(GeaTicket::where('ticket_id', $ticket_order['tickets'][0]['ticket']['id'])->first())) {
                $ticketid = "SHW0" . showKey();
                $id = $ticket_order['tickets'][0]['ticket']['id'];

//                $data = [
//                    "showId"            => (int)GeaShows::where('event_id', $event_id)->first()->eimsId,
//                    "externalId"        => GeaTicket::where('event_id', $event_id)->first()->ticketExternalId,
//                    "quantity"          => $ticket_order['tickets'][0]['qty'],
//                    "actualPrice"       => $ticket_order['tickets'][0]['price'],
//                    "ChannelId"           => isset($request_data['pay_offline']) ? 0 : 1,
//                    "fullName"          => strip_tags($request_data['order_first_name']) . ' ' . strip_tags($request_data['order_last_name']),
//                    "email"             => $request_data['order_email'],
//                    "mobileNumber"      => $request_data['order_mobile'],
//                    "dateOfBirthGregorian" => '0000-00-00',
//                    "MaleCount"         => $ticket_order['tickets'][0]['qty'],
//                    "FemaleCount"       => 0,
//                    "ChildCount"        => 0,
//                    "DateFrom"          => ($ticket_order['tickets'][0]['ticket']['start_sale_date'] == null) ? GeaShows::where('event_id', $event_id)->first()->eventDateFrom : Carbon::parse($ticket_order['tickets'][0]['ticket']['start_sale_date'])->toDateString(),
//                    "DateTo"            => ($ticket_order['tickets'][0]['ticket']['end_sale_date'] == null) ? GeaShows::where('event_id', $event_id)->first()->eventDateTo : Carbon::parse($ticket_order['tickets'][0]['ticket']['end_sale_date'])->toDateString(),
//                    "TimeFrom"          => ($ticket_order['tickets'][0]['ticket']['start_sale_date'] == null) ? GeaShows::where('event_id', $event_id)->first()->eventTimeFrom : Carbon::parse($ticket_order['tickets'][0]['ticket']['start_sale_date'])->format('H:i'),
//                    "TimeTo"            => ($ticket_order['tickets'][0]['ticket']['end_sale_date'] == null) ? GeaShows::where('event_id', $event_id)->first()->eventTimeTo : Carbon::parse($ticket_order['tickets'][0]['ticket']['end_sale_date'])->format('H:i'),
//                    "sellingDate"       => date('Y-m-d\TH:i:s'),
//                ];
//
//
//
//
//                 try {
//                     $client = new Client();
//                     $url = env("GEA_TICKET_URL");
//                     $response = $client->post($url, [
//                         'headers' => ['X-Api-Key' => env('GEA_TOKEN')],
//                         'form_params' => $data]);
//                     $responseJSON = json_decode($response->getBody(), true);
//
//                 }catch (\Exception $e) {
//                     Log::error($e);
//                     return response()->json([
//                         'status' => 'error',
//                         'messages' => $e,
//                     ]);
//                 }
//                $issue_data = [
//                    "showId" => (int)GeaShows::where('event_id', $event_id)->first()->eimsId,
//                    "externalId" => $ticketid,
//                    "quantity" => $ticket_order['tickets'][0]['qty'],
//                    "actualPrice" => $ticket_order['tickets'][0]['price'],
//                    "channel" => isset($request_data['pay_offline']) ? 0 : 1,
//                    "fullName" => strip_tags($request_data['order_first_name']) . ' ' . strip_tags($request_data['order_last_name']),
//                    "email" => $request_data['order_email'],
//                    "mobileNumber" => $request_data['order_mobile'],
//                    "dateOfBirthGregorian" => '0000-00-00',
//                    "malesCount" => 2,
//                    "femalesCount" => 2,
//                    "childrenCount" => 1,
//                    "ticketDateFrom" => ($ticket_order['tickets'][0]['ticket']['start_sale_date'] == null) ? GeaShows::where('event_id', $event_id)->first()->eventDateTo : $ticket_order['tickets'][0]['ticket']['start_sale_date'],
//                    "ticketDateTo" => ($ticket_order['tickets'][0]['ticket']['end_sale_date'] == null) ? GeaShows::where('event_id', $event_id)->first()->eventDateFrom : $ticket_order['tickets'][0]['ticket']['end_sale_date'],
//                    "ticketTimeFrom" => "18:00",
//                    "ticketTimeTo" => "20:00",
//                    "sellingDate" => date('Y-m-d\TH:i:s'),
//                    "order_id" => (int) $order->id,
//                    "event_id" => (int) $event_id,
//                    "ticket_id" => $ticket_order['tickets'][0]['ticket']['id'],
//                    "eimsId" => $responseJSON['result']['referenceKey']
//                ];
//
//                GeaIssueTicket::create($issue_data);

            }

            /*
             * Update the event sales volume
             */
            $event->increment('sales_volume', $orderService->getGrandTotal());
            $event->increment('organiser_fees_volume', $order->organiser_booking_fee);

            /*
             * Update affiliates stats stats
             */
            if ($ticket_order['affiliate_id']) {
                $affiliate = Affiliate::find($ticket_order['affiliate_id']);
                if ($affiliate) {
                    $affiliate->increment('sales_volume', $order->amount + $order->organiser_booking_fee);
                    $affiliate->increment('tickets_sold', $ticket_order['total_ticket_quantity']);
                }
            }

            /*
             * Update the event stats
             */
            if ($event->is_multiple == 0) {
            $event_stats = EventStats::updateOrCreate([
                'event_id' => $event_id,
                'date'     => DB::raw('CURRENT_DATE'),
            ]);

            $event_stats->increment('tickets_sold', $ticket_order['total_ticket_quantity']);

            if ($ticket_order['order_requires_payment'] && !isset($ticket_order['became_free'])) {
                $event_stats->increment('sales_volume', $order->amount);
                $event_stats->increment('organiser_fees_volume', $order->organiser_booking_fee);
            }


            } else {

                $multiple_event_stats = MultipleEventStats::where('event_id', $event_id)

                                                            // ->where('schedule_date', $attend_date)
                                                            ->where(function($query) use ($attend_date) {
                                                                $query->where('current_date', Carbon::now()->format('Y-m-d'))
                                                                      ->where('schedule_date', $attend_date);
                                                            })
                                                            ->first();


                if ($multiple_event_stats) {
                    $multiple_event_stats->increment('tickets_sold', $ticket_order['total_ticket_quantity']);

                    if ($ticket_order['order_requires_payment'] && !isset($ticket_order['became_free'])) {
                        $multiple_event_stats->increment('sales_volume', $order->amount);
                        $multiple_event_stats->increment('organiser_fees_volume', $order->organiser_booking_fee);
                    }
                } else {
                    $new_event_stats = MultipleEventStats::updateOrCreate([
                    'event_id' => $event_id,
                    'schedule_date' => $attend_date,
                    'current_date'     => Carbon::now()->format('Y-m-d'),
                    ]);
                    $new_event_stats->increment('tickets_sold', $ticket_order['total_ticket_quantity']);

                    if ($ticket_order['order_requires_payment'] && !isset($ticket_order['became_free'])) {
                    $new_event_stats->increment('sales_volume', $order->amount);
                    $new_event_stats->increment('organiser_fees_volume', $order->organiser_booking_fee);
                    }
                }
            }


            $eventDate = MultipleEvent::where('event_id', $event_id)->where('schedule_date', $attend_date)->first();
            $multiple_event_tickets = MultipleEventTicket::where('event_id', $event_id)
                                                            ->where('schedule_date', $attend_date)
                                                            ->get();

            if($eventDate) {
                $eventDate->increment('number_of_tickets', $ticket_order['total_ticket_quantity']);

                $real_quantity = [];

                foreach ($ticket_order['tickets'] as $attendee_details) {


                    if($attendee_details['qty'] > 0) {
                        array_push($real_quantity, $attendee_details);

                        $eventDate->increment('sales_volume', ($attendee_details['ticket']['price'] * $attendee_details['qty']));
                        $eventDate->increment('organiser_fees_volume',
                                        ($attendee_details['ticket']['organiser_booking_fee'] * $attendee_details['qty']));
                        $eventDate->increment('revenue', (($attendee_details['ticket']['price'] * $attendee_details['qty']) + ($attendee_details['ticket']['organiser_booking_fee'] * $attendee_details['qty'])));
                    }
                }

                foreach($real_quantity as $real) {
                    foreach($multiple_event_tickets as $multiple_event_ticket) {
                        if($multiple_event_ticket->ticket_id === $real["ticket"]["id"]) {
                            $multiple_event_ticket->increment('quantity_sold', $real['qty']);
                            $multiple_event_ticket->increment('sales_volume', ($real['ticket']['price'] * $real['qty']));
                            $multiple_event_ticket->increment('organiser_fees_volume',
                            ($real['ticket']['organiser_booking_fee'] * $real['qty']));
                            $multiple_event_ticket->increment('revenue', (($real['ticket']['price'] * $real['qty']) + ($real['ticket']['organiser_booking_fee'] * $real['qty'])));
                        }
                    }
                }
            }

            /*
             * Add the attendees
             */
            foreach ($ticket_order['tickets'] as $attendee_details) {

                // return $attendee_details;
                // return $request_data;

                /*
                 * Update ticket's quantity sold
                 */
                $ticket = Ticket::findOrFail($attendee_details['ticket']['id']);


                /*
                 * Update some ticket info
                 */
                $ticket->increment('quantity_sold', $attendee_details['qty']);
                $ticket->increment('sales_volume', ($attendee_details['ticket']['price'] * $attendee_details['qty']));
                $ticket->increment('organiser_fees_volume',
                    ($attendee_details['ticket']['organiser_booking_fee'] * $attendee_details['qty']));

                if(!empty( $attendee_details['seat_sold'])){
                    if(!empty($ticket->seat_sold_letter)){
                        $seats = array_merge($ticket->seat_sold_letter,  $attendee_details['merge']);
                        $ticket->update(['seat_sold' => $attendee_details['seat_sold'], 'seat_sold_letter' => $seats]);
                    } else {
                        $ticket->update(['seat_sold' => $attendee_details['seat_sold'], 'seat_sold_letter' => $attendee_details['merge']]);
                    }

                }



                /*
                 * Insert order items (for use in generating invoices)
                 */
                $orderItem = new OrderItem();
                $orderItem->title = $attendee_details['ticket']['title'];
                $orderItem->quantity = $attendee_details['qty'];
                $orderItem->order_id = $order->id;
                $orderItem->unit_price = $attendee_details['ticket']['price'];
                $orderItem->unit_booking_fee = $attendee_details['ticket']['booking_fee'] + $attendee_details['ticket']['organiser_booking_fee'];
                $orderItem->save();

                /*
                 * Create the attendees
                 */
                for ($i = 0; $i < $attendee_details['qty']; $i++) {

                    $attendee = new Attendee();
//                    if (isset($request_data["ticket_holder_badge"][$i][$attendee_details['ticket']['id']])) {
//                        $attendee->badge_path = $request_data["ticket_holder_badge"][$i][$attendee_details['ticket']['id']];
//                    }
                    if (isset($request_data["ticket_holder_mofa_request"][$i][$attendee_details['ticket']['id']])
                        && $request_data["ticket_holder_mofa_request"][$i][$attendee_details['ticket']['id']]) {
                        $attendee->mofa_request_id = $request_data["ticket_holder_mofa_request"][$i][$attendee_details['ticket']['id']];
                        $mofaRequest = MofaRequest::find($attendee->mofa_request_id);
                        $url = env('ENABLE_MOFA_TEST') ? 'https://g2gtest.mofa.gov.sa/EventsG2gService/EventsService.svc?singleWsdl' : 'https://g2g.mofa.gov.sa/EventsG2gService/EventsService.svc?singleWsdl';

                        $tryAgain = true;
                        $tries = 1;
                        while ($tryAgain) {
                            try {
                                $tryAgain = false;
                                $options = [
                                    'trace'      => 1,
                                    'exceptions' => 0
                                ];
                                if(!env('ENABLE_MOFA_TEST')){
                                    $options['stream_context'] = stream_context_create(
                                        [
                                            'socket' => ['bindto' => '138.68.157.239:0'],
                                            'ssl' => [
                                                'verify_peer' => false,
                                                'verify_peer_name' => false,
                                                'allow_self_signed' => true
                                            ]

                                        ]
                                    );
                                }

                                $client = new SoapClient($url, $options);
                                $requestData = [
                                    'IssueEventVisitRequest' => [
                                        'ReferanceNo' => $mofaRequest->referance_no,
                                        'SponsorID'   => '7010995228'
                                    ]
                                ];

                                if (!env('ENABLE_MOFA_TEST')) {
                                    $requestData['IssueEventVisitRequest'] += getMOFACredentials();
                                }

                                $issueResponse = $client->__soapCall('IssueEventVisitRequest', $requestData);
                            } catch (\Exception $e) {
//                                $dirName = base_path('traceMofa/initial/' . now()->format('Y_m_d_H_i_s_u'));
//                                mkdir($dirName);
//                                file_put_contents($dirName . '/request.xml', $client->__getLastRequest());
//                                file_put_contents($dirName . '/response.xml', $client->__getLastRequest());
//                                file_put_contents($dirName . '/response_headers.xml', $client->__getLastRequest());
                                if ($tries == 5) {
                                    throw $e;
                                    break;
                                }
                                sleep(1);
                                $tries++;
                                $tryAgain = true;
                            }
                        }


                        $mofaRequest->issued_successfully = $issueResponse->IssueEventVisitStatus->IssuedSuccuessfully;
                        $mofaRequest->visa_no = $issueResponse->IssueEventVisitStatus->VisaNumber;
                        $mofaRequest->save();

                    }
                    $attendee->first_name = strip_tags($request_data["ticket_holder_first_name"][$i][$attendee_details['ticket']['id']]);
                    $attendee->last_name = strip_tags($request_data["ticket_holder_last_name"][$i][$attendee_details['ticket']['id']]);
                    $attendee->email = $request_data["ticket_holder_email"][$i][$attendee_details['ticket']['id']];
                    (!empty($request_data["ticket_holder_seat"][$i][$attendee_details['ticket']['id']])) ? $attendee->seat = $request_data["ticket_holder_seat"][$i][$attendee_details['ticket']['id']] : '';

                    // $attendee->attend_date = $request_data["ticket_holder_attend_date"][$i][$attendee_details['ticket']['id']];
                    $attendee->attend_date = $attend_date ? $attend_date : null;
                    $attendee->event_id = $event_id;
                    $attendee->order_id = $order->id;
                    $attendee->ticket_id = $attendee_details['ticket']['id'];
                    $attendee->account_id = $event->account->id;
                    $attendee->reference_index = $attendee_increment;
                    $attendee->save();

                    if(array_key_exists('seller_slug', $request_data)){
                        $seller_stat = new SellerStats();
                        $seller_stat->event_id = $event_id;
                        $seller_stat->attendee_id = $attendee->id;
                        $seller_stat->order_id = $order->id;
                        $seller_stat->ticket_id = $attendee_details['ticket']['id'];
                        $seller_stat->event_name = $event->title;
                        $seller_stat->ticket_name = $attendee_details['ticket']['title'];
                        $seller_stat->ticket_amount = $attendee_details['ticket']['price'];
                        $seller_stat->order_reference = $order->order_reference;
                        $seller_stat->attendee_email = $request_data["ticket_holder_email"][$i][$attendee_details['ticket']['id']];
                        $seller_stat->attend_date = $attend_date ? $attend_date : date("Y-m-d", strtotime($event->start_date)) . " - " . date("Y-m-d", strtotime($event->end_date));
                        $seller_stat->seller_slug = $request_data['seller_slug'];
                        $seller_stat->payment_type = $request_data['offline_payment_type'];
                        $seller_stat->save();
                    }


                    if(!empty($request_data["ticket_holder_seat"][$i][$attendee_details['ticket']['id']])){
                        ReservedSeat::where('ticket_id',  $attendee_details['ticket']['id'])->where('event_id', $event_id)->where('seat', $request_data["ticket_holder_seat"][$i][$attendee_details['ticket']['id']])->delete();

                    }



                        /*
                         * Save the attendee's questions
                         */
                    foreach ($attendee_details['ticket']->questions as $question) {


                        $ticket_answer = isset($ticket_questions[$attendee_details['ticket']->id][$i][$question->id]) ? $ticket_questions[$attendee_details['ticket']->id][$i][$question->id] : null;

                        if (is_null($ticket_answer)) {
                            continue;
                        }

                        /*
                         * If there are multiple answers to a question then join them with a comma
                         * and treat them as a single answer.
                         */
                        $ticket_answer = is_array($ticket_answer) ? implode(', ', $ticket_answer) : $ticket_answer;

                        if (!empty($ticket_answer)) {
                            QuestionAnswer::create([
                                'answer_text' => $ticket_answer,
                                'attendee_id' => $attendee->id,
                                'event_id'    => $event->id,
                                'account_id'  => $event->account->id,
                                'question_id' => $question->id
                            ]);

                        }
                    }


                    /* Keep track of total number of attendees */
                    $attendee_increment++;
                }
            }

        } catch (Exception $e) {

            Log::error($e);
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Whoops! There was a problem processing your order. Please try again.'
            ]);

        }
        //save the order to the database
        DB::commit();
        //forget the order in the session
        session()->forget('ticket_order_' . $event->id);

        // Queue up some tasks - Emails to be sent, PDFs etc.
        Log::info('Firing the event');
        event(new OrderCompletedEvent($order));


        if(array_key_exists('seller_slug', $request_data)){
            if ($return_json) {
                return response()->json([
                    'status'      => 'success',
                    'redirectUrl' => route('showOrderSellerDetails', [
                        'is_embedded'     => $this->is_embedded,
                        'seller_slug' => $request_data['seller_slug'],
                        'order_reference' => $order->order_reference,
                    ]),
                ]);
            }
            return response()->redirectToRoute('showOrderSellerDetails', [
                'is_embedded'     => $this->is_embedded,
                'seller_slug' => $request_data['seller_slug'],
                'order_reference' => $order->order_reference,
            ]);
        } else {
            if ($return_json) {
                return response()->json([
                    'status'      => 'success',
                    'redirectUrl' => route('showOrderDetails', [
                        'is_embedded'     => $this->is_embedded,
                        'order_reference' => $order->order_reference,
                    ]),
                ]);
            }

            return response()->redirectToRoute('showOrderDetails', [
                'is_embedded'     => $this->is_embedded,
                'order_reference' => $order->order_reference,
            ]);
        }
    }

    /**
     * Show the order details page
     *
     * @param Request $request
     * @param $order_reference
     * @return \Illuminate\View\View
     */
    public function showOrderDetails(Request $request, $order_reference)
    {
        $order = Order::where('order_reference', '=', $order_reference)->first();

        if (!$order) {
            abort(404);
        }

        $orderService = new OrderService($order->amount, $order->organiser_booking_fee, $order->event, $order->discount);
        $orderService->calculateFinalCosts();

        $data = [
            'order'        => $order,
            'orderService' => $orderService,
            'event'        => $order->event,
            'tickets'      => $order->event->tickets,
            'is_embedded'  => $this->is_embedded,
        ];

        if ($this->is_embedded) {
            return view('Public.ViewEvent.Embedded.EventPageViewOrder', $data);
        }

        return view('Public.ViewEvent.EventPageViewOrder', $data);
    }

    public function showOrderSellerDetails(Request $request, $order_reference)
    {
        $order = Order::where('order_reference', '=', $order_reference)->first();

        if (!$order) {
            abort(404);
        }

        $orderService = new OrderService($order->amount, $order->organiser_booking_fee, $order->event, $order->discount);
        $orderService->calculateFinalCosts();

        $data = [
            'order'        => $order,
            'orderService' => $orderService,
            'event'        => $order->event,
            'tickets'      => $order->event->tickets,
            'is_embedded'  => $this->is_embedded,
            'seller_slug'   => $request->seller_slug
        ];

        return view('Public.ViewEvent.SellerPages.EventPageViewOrder', $data);
    }

    /**
     * Shows the tickets for an order - either HTML or PDF
     *
     * @param Request $request
     * @param $order_reference
     * @return \Illuminate\View\View
     */
    public function showOrderTickets(Request $request, $order_reference)
    {

        // return $request;
        Log::info("this is the request");
        Log::info($request->all());


        $order = Order::where('order_reference', '=', $order_reference)->first();

        if (!$order) {
            abort(404);
        }
        if ($order->order_status_id == config('attendize.order_awaiting_payment')) {
            abort(404);
        }
        $images = [];
        $imgs = $order->event->images;
        foreach ($imgs as $img) {
            $images[] = base64_encode(file_get_contents( $img->image_path));
        }


        $data = [
            'order'     => $order,
            'event'     => $order->event,
            'tickets'   => $order->event->tickets,
            'attendees' => $order->attendees,
            'css'       => file_get_contents(public_path('css/ticket.css')),
            'image'     => $order->event->images->count() > 0 ?  $order->event->images[0]->image_path :  $order->event->bg_image_path ,
            'images'    => $images,
            // 'attend_date' => $attend_date
        ];

        if ($request->get('download') == '1') {

            return PDF::loadView('Public.ViewEvent.Partials.PDFTicket', $data, [], [
                'orientation' => 'L'
            ])->download('Tickets.pdf');
        }
        return view('Public.ViewEvent.Partials.PDFTicket', $data);
    }

    public function postPromoCode(Request $request, $event_id)
    {
        $orderSession = session()->get('ticket_order_' . $event_id);
        unset($orderSession['coupon']);
        session()->put('ticket_order_' . $event_id, $orderSession);

        $event = Event::findOrFail($event_id);

        $orderService = new OrderService($orderSession['order_total'], $orderSession['total_booking_fee'], $event);
        $orderService->calculateFinalCosts();

        $taxAmount = $orderService->getTaxAmount(true);
        $grandTotal = $orderService->getGrandTotal(true);
        $oldTotal = $orderService->getOrderTotalWithBookingFee(true);

        if (!$request->get('coupon')) {
            return response()->json([
                'status'   => 'error',
                'messages' => [
                    'coupon' => [trans('Controllers.coupon_empty')]
                ],
                'runThis'  => "runAfterApplyCouponCode('','{$oldTotal}','{$grandTotal}','','{$taxAmount}')"
            ]);
        }

        $coupon = PromoCode::active()->where('code', $request->get('coupon'))
            ->where('event_id', $event->id)->first();
        if (!$coupon) {
            return response()->json([
                'status'   => 'error',
                'messages' => [
                    'coupon' => [trans('Controllers.coupon_not_exist')]
                ],
                'runThis'  => "runAfterApplyCouponCode('','{$oldTotal}','{$grandTotal}','','{$taxAmount}')"
            ]);
        }
        if ($coupon->limit && $coupon->orders()->count() >= $coupon->limit) {
            return response()->json([
                'status'   => 'error',
                'messages' => [
                    'coupon' => [trans('This Coupon Code has Expired')]
                ],
                'runThis'  => "runAfterApplyCouponCode('','{$oldTotal}','{$grandTotal}','','{$taxAmount}')"
            ]);
        }
        if ($coupon->type == 'percent') {
            $discountValue = $orderService->getOrderTotalWithBookingFee() * $coupon->amount / 100.0;
            $discountFormatted = (int)$coupon->amount . '%';
        } else {
            $discountValue = $coupon->amount * $orderSession['total_ticket_quantity'] > $orderService->getOrderTotalWithBookingFee() ? $orderService->getOrderTotalWithBookingFee() : $coupon->amount * $orderSession['total_ticket_quantity'];
            $discountFormatted = money($discountValue, $event->currency);
        }

        $orderService = new OrderService($orderSession['order_total'], $orderSession['total_booking_fee'], $event, $discountValue);
        $orderService->calculateFinalCosts();
        $data['code'] = $coupon->code;
        $data['id'] = $coupon->id;
        $data['coupon_discount_formatted'] = $discountFormatted;
        $data['order_total_after_discount'] = $orderService->getOrderTotalWithBookingFee(true);
        $data['order_grand_total_after_discount'] = $orderService->getGrandTotal(true);
        $data['discount_amount'] = $discountValue;
        $orderSession['coupon'] = $data;
        $orderSession['discount_amount'] = $discountValue;
        $taxAmount = $orderService->getTaxAmount(true);

        if ($orderService->getGrandTotal(false) == 0) {
            $orderSession['became_free'] = true;
            session()->put('ticket_order_' . $event->id, $orderSession);
            return response()->json([
                'status'  => 'success',
                'runThis' => "runAfterApplyCouponCode('{$discountFormatted}','{$data['order_total_after_discount']}','{$data['order_grand_total_after_discount']}','{$oldTotal}','{$taxAmount}');removePayment();"
            ]);

        }
        session()->put('ticket_order_' . $event->id, $orderSession);


        return response()->json([
            'status'  => 'success',
            'runThis' => "runAfterApplyCouponCode('{$discountFormatted}','{$data['order_total_after_discount']}','{$data['order_grand_total_after_discount']}','{$oldTotal}','{$taxAmount}')"
        ]);
    }

    public function handlePayment($request, $event, $event_id, $ticket_order)
    {
        /*
         * Check if the user has chosen to pay offline
         * and if they are allowed
         */
        if ($request->get('pay_offline') && $event->enable_offline_payments) {
            return $this->completeOrder($event_id, $request->attend_date);
        }
        if (isset($ticket_order['discount_amount'])) {
            $orderService = new OrderService($ticket_order['order_total'], $ticket_order['total_booking_fee'], $event, $ticket_order['discount_amount']);
        } else {
            $orderService = new OrderService($ticket_order['order_total'], $ticket_order['total_booking_fee'], $event);
        }
        $orderService->calculateFinalCosts();
        // HANDLE PAYFORT ALONE FOR NOW
        if (!config('attendize.enable_dummy_payment_gateway')
            && isset($ticket_order['payment_gateway']) && optional($ticket_order['payment_gateway'])->id == config('attendize.payment_gateway_payfort')) {

            // if($event->is)
            return $this->handlePayfortPayment($request, $event, $event_id, $ticket_order, $orderService);
        }


        try {
            $transaction_data = [];
            if (config('attendize.enable_dummy_payment_gateway') == TRUE) {
                $formData = config('attendize.fake_card_data');
                $transaction_data = [
                    'card' => $formData
                ];

                $gateway = Omnipay::create('Dummy');
                $gateway->initialize();

            } else {
                $gateway = Omnipay::create($ticket_order['payment_gateway']->name);
                $gateway->initialize($ticket_order['account_payment_gateway']->config + [
                        'testMode' => config('attendize.enable_test_payments'),
                    ]);
            }


            $transaction_data += [
                'amount'      => $orderService->getGrandTotal(),
                'currency'    => $event->currency->code,
                'description' => 'Order for customer: ' . $request->get('order_email'),
            ];

            switch ($ticket_order['payment_gateway']->id) {
                case config('attendize.payment_gateway_dummy'):
                    $token = uniqid();
                    $transaction_data += [
                        'token'         => $token,
                        'receipt_email' => $request->get('order_email'),
                        'card'          => $formData
                    ];
                    break;
                case config('attendize.payment_gateway_paypal'):

                    $transaction_data += [
                        'cancelUrl' => route('showEventCheckoutPaymentReturn', [
                            'event_id'             => $event_id,
                            'is_payment_cancelled' => 1
                        ]),
                        'returnUrl' => route('showEventCheckoutPaymentReturn', [
                            'event_id'              => $event_id,
                            'is_payment_successful' => 1
                        ]),
                        'brandName' => isset($ticket_order['account_payment_gateway']->config['brandingName'])
                            ? $ticket_order['account_payment_gateway']->config['brandingName']
                            : $event->organiser->name
                    ];
                    break;
                case config('attendize.payment_gateway_stripe'):
                    $token = $request->get('stripeToken');
                    $transaction_data += [
                        'token'         => $token,
                        'receipt_email' => $request->get('order_email'),
                    ];
                    break;
                default:
                    Log::error('No payment gateway configured.');
                    return repsonse()->json([
                        'status'  => 'error',
                        'message' => 'No payment gateway configured.'
                    ]);
                    break;
            }

            $transaction = $gateway->purchase($transaction_data);

            $response = $transaction->send();

            if ($response->isSuccessful()) {

                session()->push('ticket_order_' . $event_id . '.transaction_id',
                    $response->getTransactionReference());

                return $this->completeOrder($event_id, $request->attend_date);

            } elseif ($response->isRedirect()) {

                /*
                 * As we're going off-site for payment we need to store some data in a session so it's available
                 * when we return
                 */
                session()->push('ticket_order_' . $event_id . '.transaction_data', $transaction_data);
                Log::info("Redirect url: " . $response->getRedirectUrl());

                $return = [
                    'status'      => 'success',
                    'redirectUrl' => $response->getRedirectUrl(),
                    'message'     => 'Redirecting to ' . $ticket_order['payment_gateway']->provider_name
                ];

                // GET method requests should not have redirectData on the JSON return string
                if ($response->getRedirectMethod() == 'POST') {
                    $return['redirectData'] = $response->getRedirectData();
                }

                return response()->json($return);

            } else {
                // display error to customer
                return response()->json([
                    'status'  => 'error',
                    'message' => $response->getMessage(),
                ]);
            }
        } catch (\Exeption $e) {
            Log::error($e);
            $error = 'Sorry, there was an error processing your payment. Please try again.';
        }

        if ($error) {
            return response()->json([
                'status'  => 'error',
                'message' => $error,
            ]);
        }

    }

    public function handlePayfortPayment($request, $event, $event_id, $ticket_order, $orderService)
    {
        $config = $ticket_order['account_payment_gateway']['config'];
        $merchant_reference = $this->generateMerchantReference();
        session()->put('ticket_order_' . $event->id . '.transaction_ref', $merchant_reference);
        $payfortLog = new PayfortLog();
        $payfortLog->merchant_reference = $merchant_reference;
        $payfortLog->currency = $event->currency->code;
        $payfortLog->event_id = $event_id;
        $payfortLog->amount = (int)($orderService->getGrandTotal() * 100);
        $payfortLog->email = $request->order_email;
        $payfortLog->save();
        $data = array_only($config, ['access_code', 'merchant_identifier']);
        $data['merchant_reference'] = $merchant_reference;
        $data['language'] = 'en';

        if($event->is_multiple == 0) {
            Log::info("thi is a single event");
            $data['return_url'] = route('handleMerchantPage2Response',
            ['event_id'                => $event_id,
                'merchant_reference' => $merchant_reference]);
        } else {
            Log::info("thi is a multi event");
            Log::info($request->attend_date);

            $data['return_url'] = route('handleMerchantPage2ResponseMultiple',
            [
                'event_id'  => $event_id,
                'attend_date' => $request->attend_date,
                'merchant_reference' => $merchant_reference]);
        }
        

        

        $data['service_command'] = 'TOKENIZATION';
        $data['signature'] = calculatePayfortSignature($data, $config['sha_type'], $config['sha_request_phrase']);
        return response()->json([
            'is_payfort' => true,
            'data'       => $data
        ]);
    }


    /**
     * Generate Merchant Reference
     * @return string
     */
    public function generateMerchantReference(): string
    {
        $code = str_pad(mt_rand(1, 999) . substr(time(), -6) . mt_rand(1, 999), 12, mt_rand(1, 99999), STR_PAD_BOTH) . '-' . str_random(6);

        if(PayfortLog::where('merchant_reference', $code)->first())
            return $this->generateMerchantReference();

        return $code;
    }
}
