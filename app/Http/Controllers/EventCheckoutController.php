<?php

namespace App\Http\Controllers;

use App;
use App\Commands\OrderTicketsCommand;
use App\Models\Affiliate;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventStats;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReservedTickets;
use App\Models\QuestionAnswer;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Cookie;
use DB;
use Log;
use Omnipay;
use PDF;
use Validator;

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
        /*
         * Order expires after X min
         */
        $order_expires_time = Carbon::now()->addMinutes(config('attendize.checkout_timeout_after'));

        $event = Event::findOrFail($event_id);

        $ticket_ids = $request->get('tickets');

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

        foreach ($ticket_ids as $ticket_id) {
            $current_ticket_quantity = (int)$request->get('ticket_' . $ticket_id);

            if ($current_ticket_quantity < 1) {
                continue;
            }

            $total_ticket_quantity = $total_ticket_quantity + $current_ticket_quantity;

            $ticket = Ticket::find($ticket_id);

            $ticket_quantity_remaining = $ticket->quantity_remaining;

            /*
             * @todo Check max/min per person
             */
            $max_per_person = min($ticket_quantity_remaining, $ticket->max_per_person);

            $quantity_available_validation_rules['ticket_' . $ticket_id] = ['numeric', 'min:' . $ticket->min_per_person, 'max:' . $max_per_person];

            $quantity_available_validation_messages = [
                'ticket_' . $ticket_id . '.max' => 'The maximum number of tickets you can register is ' . $ticket_quantity_remaining,
                'ticket_' . $ticket_id . '.min' => 'You must select at least ' . $ticket->min_per_person . ' tickets.',
            ];

            $validator = Validator::make(['ticket_' . $ticket_id => (int)$request->get('ticket_' . $ticket_id)], $quantity_available_validation_rules, $quantity_available_validation_messages);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'messages' => $validator->messages()->toArray(),
                ]);
            }

            $order_total = $order_total + ($current_ticket_quantity * $ticket->price);
            $booking_fee = $booking_fee + ($current_ticket_quantity * $ticket->booking_fee);
            $organiser_booking_fee = $organiser_booking_fee + ($current_ticket_quantity * $ticket->organiser_booking_fee);

            $tickets[] = [
                'ticket' => $ticket,
                'qty' => $current_ticket_quantity,
                'price' => ($current_ticket_quantity * $ticket->price),
                'booking_fee' => ($current_ticket_quantity * $ticket->booking_fee),
                'organiser_booking_fee' => ($current_ticket_quantity * $ticket->organiser_booking_fee),
                'full_price' => $ticket->price + $ticket->total_booking_fee,
            ];

            /*
             * Reserve the tickets in the DB
             */
            $reservedTickets = new ReservedTickets();
            $reservedTickets->ticket_id = $ticket_id;
            $reservedTickets->event_id = $event_id;
            $reservedTickets->quantity_reserved = $current_ticket_quantity;
            $reservedTickets->expires = $order_expires_time;
            $reservedTickets->session_id = session()->getId();
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

                        if($question->is_required) {
                            $validation_rules['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id] = ['required'];
                            $validation_messages['ticket_holder_questions.' . $ticket_id . '.' . $i . '.' . $question->id . '.required'] = "This question is required";
                        }
                    }


                }

        }

        if (empty($tickets)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tickets selected.',
            ]);
        }

        /*
         * @todo - Store this in something other than a session?
         */
        session()->set('ticket_order_' . $event->id, [
            'validation_rules' => $validation_rules,
            'validation_messages' => $validation_messages,
            'event_id' => $event->id,
            'tickets' => $tickets, /* probably shouldn't store the whole ticket obj in session */
            'total_ticket_quantity' => $total_ticket_quantity,
            'order_started' => time(),
            'expires' => $order_expires_time,
            'reserved_tickets_id' => $reservedTickets->id,
            'order_total' => $order_total,
            'booking_fee' => $booking_fee,
            'organiser_booking_fee' => $organiser_booking_fee,
            'total_booking_fee' => $booking_fee + $organiser_booking_fee,
            'order_requires_payment' => (ceil($order_total) == 0) ? false : true,
            'account_id' => $event->account->id,
            'affiliate_referral' => Cookie::get('affiliate_' . $event_id),
            'account_payment_gateway' => count($event->account->active_payment_gateway) ? $event->account->active_payment_gateway : false,
            'payment_gateway' => count($event->account->active_payment_gateway) ? $event->account->active_payment_gateway->payment_gateway : false,
        ]);

        if ($request->ajax()) {
            return response()->json([
                'status' => 'success',
                'redirectUrl' => route('showEventCheckout', [
                        'event_id' => $event_id,
                        'is_embedded' => $this->is_embedded,
                    ]) . '#order_form',
            ]);
        }

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


        if (!$order_session || $order_session['expires'] < Carbon::now()) {
            return redirect()->route('showEventPage', ['event_id' => $event_id]);
        }

        $secondsToExpire = Carbon::now()->diffInSeconds($order_session['expires']);

        $data = $order_session + [
                'event' => Event::findorFail($order_session['event_id']),
                'secondsToExpire' => $secondsToExpire,
                'is_embedded' => $this->is_embedded,
            ];

        if ($this->is_embedded) {
            return view('Public.ViewEvent.Embedded.EventPageCheckout', $data);
        }

        return view('Public.ViewEvent.EventPageCheckout', $data);
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
        $mirror_buyer_info = ($request->get('mirror_buyer_info') == 'on');
        $event = Event::findOrFail($event_id);
        $order = new Order;
        $ticket_order = session()->get('ticket_order_' . $event_id);

        $validation_rules = $ticket_order['validation_rules'];
        $validation_messages = $ticket_order['validation_messages'];

        if (!$mirror_buyer_info && $event->ask_for_all_attendees_info) {
            $order->rules = $order->rules + $validation_rules;
            $order->messages = $order->messages + $validation_messages;
        }

        if (!$order->validate($request->all())) {
            return response()->json([
                'status' => 'error',
                'messages' => $order->errors(),
            ]);
        }

        /*
         * Add the request data to a session in case payment is required off-site
         */
        session()->push('ticket_order_' . $event_id . '.request_data', $request->except(['card-number', 'card-cvc']));

        /*
         * Begin payment attempt before creating the attendees etc.
         * */
        if ($ticket_order['order_requires_payment']) {
            try {

                $gateway = Omnipay::create($ticket_order['payment_gateway']->name);

                $gateway->initialize($ticket_order['account_payment_gateway']->config + [
                        'testMode' => config('attendize.enable_test_payments'),
                    ]);

                switch ($ticket_order['payment_gateway']->id) {
                    case config('attendize.payment_gateway_paypal'):
                    case config('attendize.payment_gateway_coinbase'):

                        $transaction_data = [
                            'cancelUrl' => route('showEventCheckoutPaymentReturn', [
                                'event_id' => $event_id,
                                'is_payment_cancelled' => 1
                            ]),
                            'returnUrl' => route('showEventCheckoutPaymentReturn', [
                                'event_id' => $event_id,
                                'is_payment_successful' => 1
                            ]),
                            'brandName' => isset($ticket_order['account_payment_gateway']->config['brandingName'])
                                ? $ticket_order['account_payment_gateway']->config['brandingName']
                                : $event->organiser->name
                        ];
                        break;
                        break;
                    case config('attendize.payment_gateway_stripe'):
                        $token = $request->get('stripeToken');
                        $transaction_data = [
                            'token' => $token,
                        ];
                        break;
                    default:
                        Log::error('No payment gateway configured.');
                        return repsonse()->json([
                            'status' => 'error',
                            'message' => 'No payment gateway configured.'
                        ]);
                        break;
                }

                $transaction_data = [
                        'amount' => ($ticket_order['order_total'] + $ticket_order['organiser_booking_fee']),
                        'currency' => $event->currency->code,
                        'description' => 'Order for customer: ' . $request->get('order_email'),
                    ] + $transaction_data;

                $transaction = $gateway->purchase($transaction_data);

                $response = $transaction->send();

                if ($response->isSuccessful()) {

                    session()->push('ticket_order_' . $event_id . '.transaction_id', $response->getTransactionReference());
                    return $this->completeOrder($event_id);

                } elseif ($response->isRedirect()) {

                    /*
                     * As we're going off-site for payment we need to store some into in a session
                     */
                    session()->push('ticket_order_' . $event_id . '.transaction_data', $transaction_data);

                    return response()->json([
                        'status' => 'success',
                        'redirectUrl' => $response->getRedirectUrl(),
                        'redirectData' => $response->getRedirectData(),
                        'message' => 'Redirecting to ' . $ticket_order['payment_gateway']->provider_name
                    ]);

                } else {
                    // display error to customer
                    return response()->json([
                        'status' => 'error',
                        'message' => $response->getMessage(),
                    ]);
                }
            } catch (\Exeption $e) {
                Log::error($e);
                $error = 'Sorry, there was an error processing your payment. Please try again.';
            }

            if ($error) {
                return response()->json([
                    'status' => 'error',
                    'message' => $error,
                ]);
            }
        }


        /*
         * No payment required so go ahead and complete the order
         */
        return $this->completeOrder($event_id);

    }


    public function showEventCheckoutPaymentReturn(Request $request, $event_id)
    {

        if ($request->get('is_payment_cancelled') == '1') {
            session()->flash('message', 'You cancelled your payment. You may try again.');
            return response()->redirectToRoute('showEventCheckout', [
                'event_id' => $event_id,
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
            return $this->completeOrder($event_id, false);
        } else {
            session()->flash('message', $response->getMessage());
            return response()->redirectToRoute('showEventCheckout', [
                'event_id' => $event_id,
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
    public function completeOrder($event_id, $return_json = true)
    {

        $order = new Order();
        $ticket_order = session()->get('ticket_order_' . $event_id);
        $request_data = $ticket_order['request_data'][0];
        $event = Event::findOrFail($ticket_order['event_id']);
        $attendee_increment = 1;
        $mirror_buyer_info = isset($request_data['mirror_buyer_info']) ? ($request_data['mirror_buyer_info'] == 'on') : false;
        $ticket_questions = isset($request_data['ticket_holder_questions']) ? $request_data['ticket_holder_questions'] : [];


        /*
         * Create the order
         */
        if (isset($ticket_order['transaction_id'])) {
            $order->transaction_id = $ticket_order['transaction_id'][0];
        }
        if ($ticket_order['order_requires_payment']) {
            $order->payment_gateway_id = $ticket_order['payment_gateway']->id;
        }
        $order->first_name = $request_data['order_first_name'];
        $order->last_name = $request_data['order_last_name'];
        $order->email = $request_data['order_email'];
        $order->order_status_id = config('attendize.order_complete');
        $order->amount = $ticket_order['order_total'];
        $order->booking_fee = $ticket_order['booking_fee'];
        $order->organiser_booking_fee = $ticket_order['organiser_booking_fee'];
        $order->discount = 0.00;
        $order->account_id = $event->account->id;
        $order->event_id = $ticket_order['event_id'];
        $order->save();


        /*
         * Update the event sales volume
         */
        $event->increment('sales_volume', $order->amount);
        $event->increment('organiser_fees_volume', $order->organiser_booking_fee);

        /*
         * Update affiliates stats stats
         */
        if ($ticket_order['affiliate_referral']) {
            $affiliate = Affiliate::where('name', '=', $ticket_order['affiliate_referral'])
                ->where('event_id', '=', $event_id)->first();
            $affiliate->increment('sales_volume', $order->amount + $order->organiser_booking_fee);
            $affiliate->increment('tickets_sold', $ticket_order['total_ticket_quantity']);
        }

        /*
         * Update the event stats
         */
        $event_stats = EventStats::firstOrNew([
            'event_id' => $event_id,
            'date' => DB::raw('CURRENT_DATE'),
        ]);
        $event_stats->increment('tickets_sold', $ticket_order['total_ticket_quantity']);

        if ($ticket_order['order_requires_payment']) {
            $event_stats->increment('sales_volume', $order->amount);
            $event_stats->increment('organiser_fees_volume', $order->organiser_booking_fee);
        }

        /*
         * Add the attendees
         */
        foreach ($ticket_order['tickets'] as $attendee_details) {

            /*
             * Update ticket's quantity sold
             */
            $ticket = Ticket::findOrFail($attendee_details['ticket']['id']);

            /*
             * Update some ticket info
             */
            $ticket->increment('quantity_sold', $attendee_details['qty']);
            $ticket->increment('sales_volume', ($attendee_details['ticket']['price'] * $attendee_details['qty']));
            $ticket->increment('organiser_fees_volume', ($attendee_details['ticket']['organiser_booking_fee'] * $attendee_details['qty']));

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
                $attendee->first_name = $event->ask_for_all_attendees_info ? ($mirror_buyer_info ? $order->first_name : $request_data["ticket_holder_first_name"][$i][$attendee_details['ticket']['id']]) : $order->first_name;
                $attendee->last_name = $event->ask_for_all_attendees_info ? ($mirror_buyer_info ? $order->last_name : $request_data["ticket_holder_last_name"][$i][$attendee_details['ticket']['id']]) : $order->last_name;
                $attendee->email = $event->ask_for_all_attendees_info ? ($mirror_buyer_info ? $order->email : $request_data["ticket_holder_email"][$i][$attendee_details['ticket']['id']]) : $order->email;
                $attendee->event_id = $event_id;
                $attendee->order_id = $order->id;
                $attendee->ticket_id = $attendee_details['ticket']['id'];
                $attendee->account_id = $event->account->id;
                $attendee->reference = $order->order_reference . '-' . ($attendee_increment);
                $attendee->save();


                /**
                 * Save the attendee's questions
                 */
                    foreach ($attendee_details['ticket']->questions as $question) {

                        $ticket_answer = $ticket_questions[$attendee_details['ticket']->id][$i][$question->id];

                        if(!empty($ticket_answer)) {
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

        /*
         * Kill the session
         */
        session()->forget('ticket_order_' . $event->id);

        /*
         * Queue up some tasks - Emails to be sent, PDFs etc.
         */
        $this->dispatch(new OrderTicketsCommand($order));

        if ($return_json) {
            return response()->json([
                'status' => 'success',
                'redirectUrl' => route('showOrderDetails', [
                    'is_embedded' => $this->is_embedded,
                    'order_reference' => $order->order_reference,
                ]),
            ]);
        }

        return response()->redirectToRoute('showOrderDetails', [
            'is_embedded' => $this->is_embedded,
            'order_reference' => $order->order_reference,
        ]);

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

        $data = [
            'order' => $order,
            'event' => $order->event,
            'tickets' => $order->event->tickets,
            'is_embedded' => $this->is_embedded,
        ];

        if ($this->is_embedded) {
            return view('Public.ViewEvent.Embedded.EventPageViewOrder', $data);
        }

        return view('Public.ViewEvent.EventPageViewOrder', $data);
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
        $order = Order::where('order_reference', '=', $order_reference)->first();

        if (!$order) {
            abort(404);
        }

        $data = [
            'order' => $order,
            'event' => $order->event,
            'tickets' => $order->event->tickets,
            'attendees' => $order->attendees,
        ];

        if ($request->get('download') == '1') {
            return PDF::html('Public.ViewEvent.Partials.PDFTicket', $data, 'Tickets');
        }
        return view('Public.ViewEvent.Partials.PDFTicket', $data);
    }

}
