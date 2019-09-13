<?php

namespace App\Http\Controllers\Users;


use App\Http\Controllers\Controller;
use App\Models\Activities;
use App\Models\ActivityAvailability;
use App\Models\ActivityBookingPreferences;
use App\Models\ActivityDemography;
use App\Models\ActivityLocation;
use App\Models\ActivityTypes;
use App\Models\Backpackers;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Generator\RandomGeneratorFactory;

/**
 * Class ActivityController
 * @package App\Http\Controllers\Activities
 * @author Ibrahim Lukman <khalifaibrahim.ib@gmail.com>
 */

class ActivityController extends Controller
{

    /**
     * List Activity Types
     * @return JsonResponse
     */
    public function listActivityTypes(): JsonResponse
    {
        $types = ActivityTypes::all();
        return $this->jsonResponse(['data' => $types], 200);
    }

    /**
     * List Activity Demography
     * @return JsonResponse
     */
    public function listActivityDemography(): JsonResponse
    {
        $types = ActivityDemography::all();
        return $this->jsonResponse(['data' => $types], 200);
    }


    /**
     * Fetch details
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function details(Request $request, $uuid): JsonResponse
    {

        $activity = Activities::where('uuid', $uuid)->first();

        if (!$activity)
            return $this->jsonResponse(['message' => 'Activity does not exist'], 404);

        $details = Activities::with(['photos', 'addons', 'location', 'rules', 'bookingPreferences',
            'activityLength', 'organizers', 'availability', 'reviews'])
            ->leftJoin('activity_organizers AS ao', 'activities.id', '=', 'ao.activity_id')
            ->whereRaw('activities.uuid = ? AND created_by = ?', [$uuid, $activity->created_by])
            ->first(['activities.*']);

        if (!$details)
            return $this->jsonResponse(['message' => 'Activity does not exist'], 404);

        $details->append(['demography_data', 'type_data', 'activity_availability']);

        return $this->jsonResponse(['data' => $details], 200);
    }


    /**
     * fetch homepage activities
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getHomeActivities(Request $request): JsonResponse
    {
        // Check if the user got a bearer toke
        if ($token = $request->bearerToken()) {
            try {
                $credentials = JWT::decode($token, env('BACKPACK_SECRET'), ['HS256']);
            } catch (\Throwable $t) {
            }

            // Append the user details
            if (isset($credentials) && $credentials->ut === 2 && $credentials->sub > 0) {
                $request->auth = Backpackers::find($credentials->sub);
                $request->clientType = $credentials->ut;
            }
        }

        $filter = $request->get('filter') ?? 'all';
        $type = $request->get('type') ?? 'all';
        $location = $request->get('location');
        $search = $request->get('search');
        $lat = $request->get('lat');
        $lon = $request->get('long');

        $demography = $request->get('type') ?? 'all';


        switch ($filter) {
            case 'fun_verified':
                $q = 'isFunVerified = ?';
                $conds = [1];
                break;
            case 'home_page':
                $q = 'isHomepage = ?';
                $conds = [1];
                break;
            case 'all':
                $q = 'isFunVerified = ? OR isHomepage = ? OR isFunVerified != ? OR isHomepage != ?';
                $conds = [1, 1, 0, 0];
                break;
        }

        if ($type !== 'all' && $location !== null) {
            $type = ActivityTypes::where('uuid', $type)->first();
            if($filter === 'all') {
                $res = Activities::whereRaw($q, $conds)
                    ->with(['availability'])
                    ->where('type', $type->id)
                    ->where('activities.status', 1)
                    ->where('activity_availability.start_date', '>', Carbon::now())
                    ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                        'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status', 'activities.isFunVerified', 'activities.isHomepage',
                        DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' . $lat . ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                    ->leftjoin('activity_locations', 'activities.id', 'activity_locations.activity_id')
                    ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                    ->orderBy('activities.isFunVerified', 'DESC')
                    ->orderBy('activities.isHomepage', 'DESC')
                    ->orderBy('distance')
                    ->groupBy('activities.uuid')
                    ->get();
            } else {
                $res = Activities::select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                        'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status', 'activities.isFunVerified', 'activities.isHomepage',
                        DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' . $lat . ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                    ->where('type', $type->id)
                    ->with(['availability'])
                    ->where('activities.status', 1)
                    ->where('activity_availability.start_date', '>', Carbon::now())
                    ->leftjoin('activity_locations', 'activities.id', 'activity_locations.activity_id')
                    ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                    ->orderBy('distance')
                    ->orderBy('activities.isFunVerified', 'DESC')
                    ->orderBy('activities.isHomepage', 'DESC')
                    ->groupBy('activities.uuid')
                    ->get();
            }



                $data = [
                    'main_result' => $res,
                    'other_result' => [],
                ];

                return $this->jsonResponse(['location' => 'found', 'data' => $data], 200);

//            } else {
//                if ($search !== null) {
//                    return $this->jsonResponse(['locationsear' => 'err1', 'data' => 'No Activity!'], 404);
//                }
//
//                $type = ActivityTypes::where('uuid', $type)->first();
//
//                $result = array_merge(Activities::with(['availability'])->where('type', $type->id)->whereRaw($q, $conds)
//                    ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
//                        'activities.isFunVerified', 'activities.isHomepage',
//                        'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status'])
//                    ->orderBy('id', 'DESC')->get()->toArray());
//                return $this->jsonResponse(['location' => 'not found', 'data' => $result], 200);
//            }


        } elseif ($type !== 'all' && $location === null) {

            $type = ActivityTypes::where('uuid', $type)->first();

            $result = Activities::with(['availability'])->where('type', $type->id)
                ->with(['availability'])
                ->where('activities.status', 1)
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at','activities.isFunVerified', 'activities.isHomepage',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status'])
                ->groupBy('activities.uuid')
                ->orderBy('id', 'DESC')->get()->toArray();

            if (!$result)
                return $this->jsonResponse(['data' => 'No Activity!'], 404);

            return $this->jsonResponse(['data' => $result], 200);
        }

        if ($type === 'all' && $location !== null) {
            $res = Activities::whereRaw($q, $conds)
                ->where('activities.status', 1)
                ->where('activity_availability.start_date', '>',  date('Y-m-d H:i:s'))
                ->with(['availability'])
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status', 'activities.isFunVerified', 'activities.isHomepage',
                    DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' . $lat . ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                ->leftjoin('activity_locations', 'activities.id', 'activity_locations.activity_id')
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->groupBy('activities.uuid')
                ->orderBy('distance')
                ->orderBy('activities.isFunVerified', 'DESC')
                ->orderBy('activities.isHomepage', 'DESC')
                ->get();


                $data = [
                    'main_result' => $res,
                    'other_result' => [],
                ];


                return $this->jsonResponse(['location' => 'found1', 'data' => $data], 200);
//            } else {
//                if ($search !== null) {
//                    return $this->jsonResponse(['locationsearc' => 'err2', 'data' => 'No Activity!'], 404);
//                }
//                $result = Activities::with(['availability'])->whereRaw($q, $conds)
//                    ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
//                        'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status'])
//                    ->orderBy('id', 'DESC')->get()->toArray();
//
//                return $this->jsonResponse(['location' => 'not found', 'data' => $result], 200);
//            }
        } elseif ($type === 'all' && $location === null) {
            $result = Activities::with(['availability'])->whereRaw($q, $conds)
                ->with(['availability'])
                ->where('activities.status', 1)
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status'])
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->groupBy('activities.uuid')
                ->orderBy('id', 'DESC')->get()->toArray();

            return $this->jsonResponse(['data' => $result], 200);
        }


    }

    public function activitiesByDemography(Request $request): JsonResponse
    {
        $filter = $request->get('filter') ?? 'all';
        $male = $request->get('male') ?? 'all';
        $females = $request->get('females') ?? 'all';
        $both = $request->get('both') ?? 'all';

        switch ($filter) {
            case 'families':
                $q = 'demography like ?';
                $conds = [$this->getDemograghyUuid('Family')];
                break;
            case 'male':
                $q = 'demography like ?';
                $conds = [$this->getDemograghyUuid('Males')];
                break;
            case 'females':
                $q = 'demography like ?';
                $conds = [$this->getDemograghyUuid('Females')];
                break;
            case 'both':
                $q = 'demography like ? AND demography like ?';
                $conds = [$this->getDemograghyUuid('Females'), $this->getDemograghyUuid('Males')];
                break;
        }

        $result = Activities::whereRaw($q, $conds)
            ->with(['availability'])
            ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status'])
            ->orderBy('id', 'DESC')->get()->toArray();

        return $this->jsonResponse(['data' => $result], 200);
    }


    protected function getDemograghyUuid($name)
    {
        return optional(ActivityDemography::where('name', $name)->first())->uuid;
    }

    public function headerFilter(Request $request): JsonResponse
    {
        $filter = $request->input('filter');



        $family = [];
        $female = [];
        $male = [];
        $group = [];
        $both = [];
        $lat = $request->input('lat') ?? '';
        $lon = $request->input('long') ?? '';
        $activity_type  = $request->input('type') ?? 'all';

        switch ($activity_type) {
            case $activity_type !== 'all':
                $typeData = ActivityTypes::where('uuid', $activity_type)->first();
                $q = 'type = ?';
                $conds = [$typeData->id];
                break;
            case 'all':
                $q = 'type != ?';
                $conds = [''];
                break;

        }


        if ($request->input('family')) {
            $type = $this->getDemograghyUuid('Family');
            $family = Activities::where('demography', 'like', '%' . $type . '%')
                ->whereRaw($q, $conds)
                ->with(['availability'])
                ->where('activities.status', 1)
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status','activities.isFunVerified', 'activities.isHomepage',
                    DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' .$lat. ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                ->leftjoin('activity_locations', 'activities.id',  'activity_locations.activity_id')
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->orderBy('distance')
                ->orderBy('activities.isFunVerified', 'DESC')
                ->orderBy('activities.isHomepage', 'DESC')
                ->get();

        }
        if ($request->input('female')) {
            $type = $this->getDemograghyUuid('Females');
            $female  = Activities::where('demography', 'like', '%' . $type . '%')
                ->whereRaw($q, $conds)
                ->with(['availability'])
                ->where('activities.status', 1)
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status','activities.isFunVerified', 'activities.isHomepage',
                    DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' .$lat. ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                ->leftjoin('activity_locations', 'activities.id', 'activity_locations.activity_id')
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->orderBy('distance')
                ->orderBy('activities.isFunVerified', 'DESC')
                ->orderBy('activities.isHomepage', 'DESC')
                ->get();
        }
        if ($request->input('male')) {
            $type = $this->getDemograghyUuid('Males');

            $male  = Activities::where('demography', 'like', '%' . $type . '%')
                ->whereRaw($q, $conds)
                ->with(['availability'])
                ->where('activities.status', 1)
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status','activities.isFunVerified', 'activities.isHomepage',
                    DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' .$lat. ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                ->leftjoin('activity_locations', 'activities.id', 'activity_locations.activity_id')
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->orderBy('distance')
                ->orderBy('activities.isFunVerified', 'DESC')
                ->orderBy('activities.isHomepage', 'DESC')
                ->get();
        }

        if ($request->input('group')) {

            $preference = ActivityBookingPreferences::where('is_group_available', 1)->get();
            $res = [];
            foreach ($preference as $pref) {
                $res[] = Activities::where('uuid', $pref->activity_uuid)
                    ->whereRaw($q, $conds)
                    ->with(['availability'])
                    ->where('activities.status', 1)
                    ->with(['availability', 'location'])
                    ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                        'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status',
                        'activities.isFunVerified', 'activities.isHomepage'])
                    ->where('activity_availability.start_date', '>', Carbon::now())
                    ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                    ->orderBy('isFunVerified', 'DESC')
                    ->orderBy('isHomepage', 'DESC')
                    ->get();
            }

            foreach ($res as $key => $value) {
                foreach ($value as $val) {
                    $group[] = $val;
                }
            }


        }

        if ($request->input('both')) {
            $femal = $this->getDemograghyUuid('Females');
            $mal = $this->getDemograghyUuid('Males');
            $both = Activities::where('demography', 'like', '%' . $mal . '%')
                ->where('demography', 'like', '%' . $femal . '%')
                ->whereRaw($q, $conds)
                ->with(['availability'])
                ->where('activities.status', 1)
                ->select(['activities.id', 'activities.name', 'activities.uuid', 'description', 'activities.created_at',
                    'activities.updated_at', 'activities.type', 'activities.id', 'activities.created_by', 'activities.status','activities.isFunVerified', 'activities.isHomepage',
                    DB::raw('6371 * acos(cos(radians(' . $lat . ')) 
                        * cos(radians(activity_locations.activity_lat)) 
                        * cos(radians(activity_locations.activity_long) 
                        - radians(' . $lon . ')) 
                        + sin(radians(' .$lat. ')) 
                        * sin(radians(activity_locations.activity_lat))) AS distance')])
                ->leftjoin('activity_locations', 'activities.id', 'activity_locations.activity_id')
                ->where('activity_availability.start_date', '>', Carbon::now())
                ->leftjoin('activity_availability', 'activities.id', 'activity_availability.activity_id')
                ->orderBy('distance')
                ->orderBy('activities.isFunVerified', 'DESC')
                ->orderBy('activities.isHomepage', 'DESC')
                ->get();


        }

        $result = [
            'total_result' => count($family) + count($female) + count($male) + count($both) + count($group),
            'family' => $family,
            'female' => $female,
            'male' => $male,
            'both' => $both,
            'group' => $group,
        ];

        return $this->jsonResponse(['data' => $result], 200);

    }

}
