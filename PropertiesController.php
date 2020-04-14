<?php

namespace App\Http\Controllers;

use App\InvoiceLineItem;
use App\Libraries\Datatables\PropertyDatatable;
use App\PropertyInspection;
use App\Repositories\InvoiceRepository;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

use App\Libraries\EventLogger;
use App\Libraries\Datatables\Datatable;

use App\Property;
use App\Contact;
use App\ContactType;
use App\Group;
use App\Helpers;
use App\Mapping;
use App\Contract;
use App\OrderLineItem;
use App\Amenity;

use DB;
use File;
use Exception;
use Illuminate\View\View;
use PHPExcel_Cell_DataType;
use Throwable;
use Validator;
use Excel;
use Artisan;
use Response;
use Carbon\Carbon;
use Fpdf;

use App\ReportUSMS;
use App\CodesDescriptions;

use App\Jobs\PropertyAccountingTabExport;

class PropertiesController extends Controller {
    public $log;
    public $helpers;
    public $directory_structure;
    public $mappings;
    public $phases;
    public $sub_phases;

    public function __construct() {
        $this->log = new EventLogger('properties');

        // /data/filestore/fdic-trak/files/FDIC-Trak-Dev/Groups/10010/Assets/1001000001
        $this->helpers = new Helpers;
        $this->directory_structure = [
            '/Documents',
            '/Marketing',
            '/Photos',
            '/Six Part File/1-Legal & Title/Acquisition Deed',
            '/Six Part File/1-Legal & Title/Booking Info',
            '/Six Part File/1-Legal & Title/Legal Opinions',
            '/Six Part File/1-Legal & Title/Note History',
            '/Six Part File/1-Legal & Title/Other Legal Matters',
            '/Six Part File/1-Legal & Title/Survey',
            '/Six Part File/1-Legal & Title/Title Reports',
            '/Six Part File/1-Legal & Title/Other Supporting Documents',
            '/Six Part File/2-Budget & Cases/Budgets',
            '/Six Part File/2-Budget & Cases/Cases',
            '/Six Part File/2-Budget & Cases/Operating Statements',
            '/Six Part File/3-Appraisals/Appraisals and BPOs',
            '/Six Part File/3-Appraisals/Environmental Reports',
            '/Six Part File/3-Appraisals/Insurance',
            '/Six Part File/4-Marketing & Closing/Advertising',
            '/Six Part File/4-Marketing & Closing/Broker Reports',
            '/Six Part File/4-Marketing & Closing/Closing Documents',
            '/Six Part File/4-Marketing & Closing/Inspection Reports',
            '/Six Part File/4-Marketing & Closing/Listing Agreement',
            '/Six Part File/4-Marketing & Closing/Offers',
            '/Six Part File/5-Correspondence/Correspondence',
            '/Six Part File/5-Correspondence/Memos',
            '/Six Part File/5-Correspondence/System Notes',
            '/Six Part File/6-Taxes & Participation/Participation',
            '/Six Part File/6-Taxes & Participation/Sold Status',
            '/Six Part File/6-Taxes & Participation/Tax Information'
        ];

        $field_mappings = Mapping::where("table", "properties")->get();
        $this->mappings = [];
        foreach($field_mappings as $field_mapping) {
            $this->mappings[$field_mapping->type][$field_mapping->name]["display_name"] = $field_mapping->display_name;
            $this->mappings[$field_mapping->type][$field_mapping->name]["description"] = $field_mapping->description;
            $this->mappings[$field_mapping->type][$field_mapping->name]["hidden"] = $field_mapping->hidden;
            $this->mappings[$field_mapping->type][$field_mapping->name]["required"] = $field_mapping->required;
        }

        $this->phases = DB::table('codes_descriptions')->where('type', 'phase_primary')->get();
        $this->sub_phases = DB::table('codes_descriptions')->where('type', 'phase_secondary')->orWhere('type', 'status_secondary')->orWhere('type', 'status_tertiary')->get();
    }


    public function lastUpdated($property) {
        return (string)$property->updated_at;
    }

    public function listProperties() {
        $cacheKey = 'statistics-properties-user-'.auth()->user()->id;
        $statistics = Cache::remember($cacheKey, 5, function () {
            return Property::getStatistics();
        });

        return view('properties.list', [
            'table_headings' => PropertyDatatable::getDataTableHeadings(),
            'columns' => PropertyDatatable::getDataTableColumns(),
            'endpoint' => '/properties/datatable',
            'filters' => PropertyDatatable::getDataTableFilters(),
            'statistics' => $statistics,
        ]);
    }

    /**
     * Endpoint for Datatables server side processing
     * @param Request $request
     * @return false|string
     */
    public function datatable(Request $request)
    {
        $datatable = new Datatable(PropertyDatatable::class);
        return $datatable->process($request);
    }

    public function searchProperties($search) {

        // USER PERMISSIONS
        $permissions = Auth::user()->propertyPermissions();

        $columns = [
            'id',
            'code',
            'code_temp',
            'group_code',
            'address',
            'city',
            'state',
            'zip',
            'county',
            'status_primary',
            'status_secondary',
            'status_tertiary',
            'preseizure_owners_identified_by_ia_usao',
            'tax_property_id',
            'property_management_occupancy_status',
            'type_primary',
            'type_secondary',
            'value_gross_approved',
            'date_of_act_giving_rise_to_forfeiture',
            'preliminary_order_of_forfeiture_date',
            'final_order_of_forfeiture_date',
            'budget_state',
            'budget_current_period_length'
        ];
        $propertiesQuery = Property::select($columns)
            ->where('code', 'like', '%'.$search.'%')
            ->orWhere('code_temp', 'like', '%'.$search.'%')
            ->orWhere('address', 'like', '%'.$search.'%')
            ->orWhere('city', 'like', '%'.$search.'%')
            ->orWhere('county', 'like', '%'.$search.'%')
            ->orWhere('state', 'like', '%'.$search.'%')
            ->orWhere('zip', 'like', '%'.$search.'%');

        if (request()->has('type') && request()->input('type') === 'LT') {
            $propertiesQuery->where(function ($query) {
                $query->where('status_primary', 'A');
                if (! request()->has('historical')) {
                    $query->orWhere('financial_settlement_date', '>', Carbon::now()->subDays(90)->toDateString());
                }
            });
        }

        $properties = $propertiesQuery
            ->take(10)
            ->get();

        $properties = Auth::user()->propertiesFilter($properties, $permissions);

        return request()->has('draw') ? [
                'draw' => request()->input('draw'),
                'results' => $properties
            ] : $properties;
    }

    /*
     * HighCharts demo for BT - not in active use
     * Please leave this. I use it for quick testing periodically. - Nick
     */
    public function mapProperties() {

        $aggregates = DB::table('properties')
            ->select(
                'state',
                //DB::raw('count(*) as count'),
                DB::raw('SUM(CASE WHEN status_primary = "A" THEN 1 ELSE 0 END) "active"'),
                DB::raw('SUM(CASE WHEN phase_primary = 0 AND status_primary = "A" THEN 1 ELSE 0 END) "pre_seizure"'),
                DB::raw('SUM(CASE WHEN phase_primary = 1 AND status_primary = "A" THEN 1 ELSE 0 END) "custody"'),
                DB::raw('SUM(CASE WHEN phase_primary = 2 AND status_primary = "A" THEN 1 ELSE 0 END) "property_support"'),
                DB::raw('SUM(CASE WHEN phase_primary = 3 AND status_primary = "A" THEN 1 ELSE 0 END) "disposition"'),
                DB::raw('SUM(CASE WHEN status_primary = "I" THEN 1 ELSE 0 END) "inactive"')
            )
            ->groupBy('state')
            ->get();

        // http://jsfiddle.net/gh/get/library/pure/highslide-software/highcharts.com/tree/master/samples/mapdata/countries/us/custom/us-all-territories
        // https://code.highcharts.com/mapdata/countries/us/custom/us-all-territories.js
        // http://jsfiddle.net/gh/get/library/pure/highslide-software/highcharts.com/tree/master/samples/mapdata/custom/north-america

        /*
            select state,
                count(*) count,

                SUM(CASE WHEN phase_primary = 0 THEN 1 ELSE 0 END) 'Pre-Seizure',
                SUM(CASE WHEN phase_primary = 1 THEN 1 ELSE 0 END) 'Custody',
                SUM(CASE WHEN phase_primary = 2 THEN 1 ELSE 0 END) 'Property_Support',
                SUM(CASE WHEN phase_primary = 3 THEN 1 ELSE 0 END) 'Disposition',

                sum(case when phase_secondary = 344 then 1 else 0 end) 'Pending_Recommendation',
                sum(case when phase_secondary = 345 then 1 else 0 end) 'Recommended',
                sum(case when phase_secondary = 346 then 1 else 0 end) 'Not_Recommended',
                sum(case when phase_secondary = 347 then 1 else 0 end) 'Ordered',
                sum(case when phase_secondary = 348 then 1 else 0 end) 'Scheduled',
                sum(case when phase_secondary = 349 then 1 else 0 end) 'Stabilization_Period',
                sum(case when phase_secondary = 350 then 1 else 0 end) 'Ongoing_Maintenance'

            from properties
            group by state

         */

        $statistics = [];

        foreach($aggregates as $aggregate) {

            $state = $aggregate->state;

            $phase_primary = [
                'pre_seizure' => $aggregate->pre_seizure,
                'custody' => $aggregate->custody,
                'property_support' => $aggregate->property_support,
                'disposition' => $aggregate->disposition
            ];

            if($state == 'AS') // American Samoa
                $state = 'as-6514';
            elseif($state == 'GU') // Guam
                $state = 'gu-3605';
            elseif($state == 'MP') // Northern Mariana Islands
                $state = 'mp-ti';
            elseif($state == 'PR') // Puerto Rico
                $state = 'pr-3614';
            //elseif($state == 'UM') // United States Minor Outlying Islands
            //    $state = '';
            elseif($state == 'VI') // Virgin Islands
                $state = 'vi-3617';
            //elseif($state == 'WW') // Dominican Republic
            //    $state = '';
            //elseif($state == 'XX') // Canada
            //    $state = '';
            //elseif($state == 'YY') // Bahamas
            //    $state = '';
            //elseif($state == 'ZZ') // Mexico
            //    $state = '';
            //elseif($state == 'UK') // United Kingdom
            //    $state = '';
            //elseif($state == 'CR') // Costa Rica
            //    $state = '';
            //elseif($state == 'JA') // Jamaica
            //    $state = '';
            //elseif($state == 'PH') // Philippines
            //    $state = '';
            //elseif($state == 'KN') // Saint Kitts and Nevis
            //    $state = '';
            //elseif($state == 'CY') // Cyprus
            //    $state = '';
            else
                $state = 'us-'.strtolower($state);

            $statistics['states'][] = ['hc-key' => $state, 'value' => intval($aggregate->active), 'active' => intval($aggregate->active), 'inactive' => intval($aggregate->inactive), 'phase_primary' => $phase_primary];

        }

        //return $statistics;

        return view('properties.map', [
            'statistics' => $statistics,
        ]);
    }

    public function getProperty(Request $request, $property) {



        setlocale(LC_MONETARY, 'en_US.UTF-8');
        try {

            $path = $property->getPath();
            $photos = $property->getPhotos($property->id);
            $banner = $property->generatePhotoBanner($property->id);

            // BUILD OUT CONTACT MATRIX
//            $contactTypes = Cache::remember('contact-types', 6000, function () {
            $contactTypes = ContactType::where('show_on_contacts_tab',1)->orderBy('type')->get();

            $contacts = array();
            $contactTypeDropdown = array();
            $assignedContacts = $property->contacts->pluck('id');
            foreach ($contactTypes as $contactType) {
                try {
                    $contact = $property->contacts()->wherePivot("contact_type_id", $contactType->id)->firstOrFail();

                    $contacts[$contactType->id]['type'] = $contactType->type;
                    $contacts[$contactType->id]['name'] = $contact->getName();
                    $contacts[$contactType->id]['organization'] = $contact->getOrganization();
                    $contacts[$contactType->id]['phone'] = $contact->phone_number;
                    $contacts[$contactType->id]['email'] = $contact->email;
                    $contacts[$contactType->id]['address1'] = $contact->address1;
                    $contacts[$contactType->id]['address2'] = $contact->address2;
                    $contacts[$contactType->id]['city'] = $contact->city;
                    $contacts[$contactType->id]['state'] = $contact->state;
                    $contacts[$contactType->id]['zip'] = $contact->zip;
                    $contacts[$contactType->id]['id'] = $contact->id;

                } catch (Exception $e) {
                    $contacts[$contactType->id]['type'] = null;
                    $contacts[$contactType->id]['name'] = null;
                    $contacts[$contactType->id]['organization'] = null;
                    $contacts[$contactType->id]['phone'] = null;
                    $contacts[$contactType->id]['email'] = null;
                    $contacts[$contactType->id]['address1'] = null;
                    $contacts[$contactType->id]['address2'] = null;
                    $contacts[$contactType->id]['city'] = null;
                    $contacts[$contactType->id]['state'] = null;
                    $contacts[$contactType->id]['zip'] = null;
                    $contacts[$contactType->id]['id'] = null;
                }

                $contactTypeDropdown[$contactType->id] = Contact::where('type', 1)->whereHas('types', function ($query) use ($contactType, $assignedContacts) {
                    $query->where('contact_type_id', $contactType->id)
                        // Where NOT disabled OR assigned to property
                        ->where(function ($q) use ($assignedContacts) {
                            $q->where('contacts.disabled', '!=', 1)
                                ->orWhereNull('contacts.disabled')
                                ->orWhereIn('contacts.id', $assignedContacts);
                        });
                })->get();

                $contactTypeDropdown[$contactType->id] = $contactTypeDropdown[$contactType->id]->sortBy(function ($contact) {
                    return $contact->getName();
                });
            }

            $inspection_contacts = Contact::where('type', 1)->where('disabled', 0)->whereHas('types', function ($query) {
                $query->where('contact_type_id', 11);
            })->orderBy('first_name')->get();


            // BUILD GROUPS DROPDOWN
            $groups = Group::orderBy('name')->get();
            foreach($groups as $index => $group) {
                $cascade = '';
                if(sizeOf($group->contracts) > 0) {
                    $cascade = implode(",", $group->contracts->pluck('task_order_number')->toArray());
                }
                $groups[$index]['cascade'] = $cascade;
            }

            $property_management_reason_for_3ppm_descriptions = DB::table('codes_descriptions')->where('type','=','reason_for_3ppm')->get();

            // PULL ALL CONTRACTS
            $contracts = Contract::all();

            // GENERATE BUDGET DATA
            $budget_data = $property->getBudgetData();

            // COMMISSIONS
            if(Auth::user()->can(['properties_view_commission']))
                $commissions = json_decode($property->commissions_json, true)[0];
            else
                $commissions = null;

            // PULL TASKS FROM ORDER_LINE_ITEMS TABLE
            $tasks = $property->tasks()->get();
            $tasks_filtered = Auth::user()->orderLineItemsFilter($tasks);

            // ONLY ALLOW ACCEPTED ORDERS
            $tasks = $tasks->filter(function ($task) {
                return in_array($task->order->status, [4]);
            });

            // FILTER OUT TASKS THAT ARE: "Submitted, Pending Approval", "Cancelled", "Rejected"
            //$tasks = $tasks->filter(function ($task) {
            //    return !in_array($task->status, [1,5,6]);
            //});

            $functional_areas = DB::table('codes_descriptions')->where('type','functional_area')->orderBy('description')->pluck('description', 'code')->toArray();

            // VENDOR SUMMARY FOR THE PROPERTY MANAGEMENT TAB - ONLY PULL LIVE BILLS
            // 2 - Approved
            // 4 - Held for Payment
            // 5 - Paid
            $vendor_bills_by_vendor = $property->vendor_bills->whereIn('status_code', [2,4,5])->groupBy('vendor_id');

            $vendor_summary = [];
            foreach($vendor_bills_by_vendor as $vendor_bills) {

                // BUDGET ONLY AFFECTED BY VENDOR BILLS WITH STATUS:
                // 2 - Approved
                // 4 - Held for Payment
                // 5 - Paid
                $vendor_summary[] = [$vendor_bills[0]->vendor->name, $vendor_bills[0]->vendor->first_name.' '.$vendor_bills[0]->vendor->last_name, $vendor_bills[0]->vendor->phone_number, $vendor_bills[0]->vendor->email, $vendor_bills->sum('total')];
            }

            $poller_history = json_decode($property->marketing_site_polling_history, true);
            if(!$poller_history)
                $poller_history = array();

            // IF DOWNLOADING A BUDGET PDF, STOP HERE - DEPRECATED
            /*if(Route::currentRouteName() == 'budget') {
                $pdf = PDF::loadView('properties.tabs.budget', [
                    'property' => $property,
                    'mappings' => $this->mappings,
                    'groups' => $groups,
                    'status_primary' => $status_primary,
                    'property_types' => $property_types,
                    'budget_data' => $budget_data
                    ]);
                return $pdf->download('budget.pdf');
            }*/

            foreach($tasks as $task) {

                try {
                    $functional_areas[$task->getFunctionalArea()];
                } catch (Exception $ex) {
                    dd('Exception block', $task);
                } catch (Throwable $ex) {
                    dd('Throwable block', $task);
                }
            }

            $amenities = Amenity::orderBy('input')->get();
            $property_amenities = DB::table('amenity_property')->where('property_id','=',$property->id)->get();

            // OTHERWISE, CHOOSE A VIEW BASED ON THE PATH
            if(Route::currentRouteName() == 'editProperty')
                $view = 'properties.edit';
            else
                $view = 'properties.view';

            $viewVars = array_merge(
                $property->getDropdownCodesDescriptions(),
                [
                    'property' => $property,
                    'permissions' => Auth::user()->propertyPermissions(),
                    'path' => $path,
                    'mappings' => $this->mappings,
                    'six_part_file' => $property->getSixPartFile(),
                    'photos' => $photos,
                    'banner' => $banner,
                    'contacts' => $contacts,
                    'contactTypes' => $contactTypes,
                    'contactTypeDropdown' => $contactTypeDropdown,
                    'groups' => $groups,
                    'contracts' => $contracts,
                    'property_management_reason_for_3ppm_descriptions' => $property_management_reason_for_3ppm_descriptions,
                    'poller_history' => $poller_history,
                    'budget_data' => $budget_data,
                    'budget_proposal' => str_replace(["%period%", "%budget%", "%authority%"], ["<span class='proposal_text_period'>".$property->budget_current_period_length."</span>", "<span class='proposal_text_budget'>".number_format($budget_data['SUMMARY']['total_operating_and_resolution_expenses']['recurring_non_recurring_items'], 2)."</span>", "<span class='proposal_text_authority'>B-3(a)</span>"], config('app.budget_proposal')),
                    'tasks' => $tasks,
                    'tasks_filtered' => $tasks_filtered,
                    'functional_areas' => $functional_areas,
                    'vendor_summary' => $vendor_summary,
                    'phase' => $this->phases->where('code', $property->phase_primary)->first()->description,
                    'sub_phase' => $this->sub_phases->where('id', $property->phase_secondary)->first()->description,
                    'commissions' => $commissions,
                    'amenities' => $amenities,
                    'property_amenities' => $property_amenities,
                    'inspection_contacts' => $inspection_contacts,
                    'inspection_types' => CodesDescriptions::where('type', 'inspection_type')->get(),
                ]
            );

            return view($view, $viewVars);

        } catch(ModelNotFoundException $e) {
            return back()->with('error', 'Unknown property.');
        }
    }

    public function shares(Property $property)
    {
        return view('properties.tabs.shares', [
            'owncloud_shares' => $property->getOwnCloudShares(),
            'permissions' => Auth::user()->propertyPermissions()
        ]);
    }

    public function postProperty(Request $request, $property=null) {
        // IF THIS RECORD DOES NOT EXIST, CREATE A NEW ONE
        if (!$property) {
            $property = new Property();

            $property->created_by = Auth::user()->id;
            $old_code = $request->input('code');
            $old_code_temp = $request->input('code_temp');
            $old_group_code = $request->input('group_code');
            $message = 'create';
            $changed = [];

            //$rules = Property::$rules;
            //if(!$request->input('code'))
            //    $rules['code'] .= ','.$request->input('code').',code'; // AVOID SELF-REFERENCING PROBLEM WITH DATABASE UNIQUENESS
            //if(!$request->input('code_temp'))
            //    $rules['code_temp'] .= ','.$request->input('code_temp').',code_temp';
        }
        else {
            $old_code = $property->code;
            $old_code_temp = $property->code_temp;
            $old_group_code = $property->group_code;
            $message = 'update';

            // TODO: use preg_match to check for field name patterns, rather that explcit names. For example: 'amenity-.*?-subtype'
            $changed = $this->helpers->passive_validation($request->all(), $property, ['_token', 'previous_route', 'contacts', 'amenities', 'amenity-4-subtype']);
        }

        // INPUT VALIDATION
        //$rules = Property::$rules;
        list($rules, $names) = $property->validationRulesNames();

        $rules['code'] = [
            'required_without:code_temp'
        ];
        $rules['code'][] = 'sometimes';
        if($request->input('code')) {
            $rules['code'][] = Rule::unique('properties')->ignore($property->id);
            $rules['code'][] = 'alpha_dash';
        }
        $rules['code_temp'] = [
            'required_without:code'
        ];
        $rules['code_temp'][] = 'sometimes';
        if($request->input('code_temp')) {
            $rules['code_temp'][] = Rule::unique('properties')->ignore($property->id);
            $rules['code_temp'][] = 'alpha_dash';
        }

        // TIMESHARES HAVE SOME SPECIAL TWEAKS TO THE RULE SET
        if($request->input('type_secondary') == 29){
            //$rules['address'] = str_replace("|sometimes|required","",$rules['address']);
            $rules['city'] = str_replace("|sometimes|required","",$rules['city']);
            $rules['state'] = str_replace("|sometimes|required","",$rules['state']);
            $rules['name'] = "sometimes|required|".$rules['name'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->setAttributeNames($names);

        if ($validator->fails()) {
            //dd($request->input('contacts'));
            return redirect()->back()->withInput($request->all())->withErrors($validator);
        }

        // INPUT CLEANUP
        $ignore = ['net_equity_executive_summary_note', 'net_equity_title_comments_note', 'net_equity_property_expenses_note', 'net_equity_sales_related_expenses_note', 'preseizure_condition_report_title_notes'];

        foreach($request->all() as $index => $value) {

            if(!is_array($value) && !in_array($index, $ignore)) {

                // REMOVE "N/A", "n/a", "N/a", "n/A" - BT 2016-08-23
                if(preg_match("/n\/a/i", $value, $matches) > 0) {
                    //$request[$index] = "";
                    $request[$index] = str_replace(array("N/A", "N/a", "n/A", "n/a"), '', $value);

                }

                // REMOVE CRLF - QT 2016-08-26
                if(preg_match("/[\r\n]/", $value, $matches) > 0) {
                    $request[$index] = str_replace(array("\r", "\n"), ' ', $value);
                }
            }
        }

        // IS THE PROPERTY MOVING FROM ACTIVE TO INACTIVE? CHECK NOW BEFORE THEY ARE SET BELOW
        if($property->status_primary == "A" && $request->input("status_primary") == "I")
            $set_default_end_dates = true;
        else
            $set_default_end_dates = false;

        // POPULATE ALL FIELDS

        // MAIN TAB
        if(Auth::user()->can('main_tab_edit')) {
            $property->comments = $request->input("comments");
            $property->description = $request->input("description");
        }

        // ACQUISITION TAB
        if(Auth::user()->can('acquisition_tab_edit')) {
            $property->group_code = $request->input("group_code");
            $property->task_order = $request->input("task_order");
            $property->code = $request->input("code");
            $property->code_temp = $request->input("code_temp");
            $property->name = $request->input("name");
            $property->address = $request->input("address");
            $property->city = $request->input("city");
            $property->state = $request->input("state");
            $property->zip = $request->input("zip");
            $property->county = $request->input("county");

            $property->coordinates = $request->input("coordinates");
            $property->number_of_properties = $request->input("number_of_properties");
            $property->lot_size = $request->input("lot_size");
            $property->building_size = $request->input("building_size");
            $property->number_of_beds = $request->input('number_of_beds');
            $property->number_of_baths = $request->input("number_of_baths");
            $property->year_built = $request->input("year_built");

            $property->type_primary = $request->input("type_primary");
            $property->type_secondary = $request->input("type_secondary");
            $property->type_tertiary = $request->input("type_tertiary");

            $property->property_management_leased_space = $request->input("property_management_leased_space");
            $property->property_management_occupancy_status = $request->input("property_management_occupancy_status");

            $property->status_primary = $request->input("status_primary");
            $property->unmarketable = $request->input("unmarketable");
            $property->status_secondary = $request->input("status_secondary");
            $property->status_tertiary = $request->input("status_tertiary");
            $property->marketing_strategy_code = $request->input("marketing_strategy_code");

            $property->office_code = $request->input("office_code");

            $property->date_of_act_giving_rise_to_forfeiture = $request->input('date_of_act_giving_rise_to_forfeiture');
            $property->preliminary_order_of_forfeiture_date = $request->input('preliminary_order_of_forfeiture_date');
            $property->final_order_of_forfeiture_date = $request->input('final_order_of_forfeiture_date');

            $property->acquisition_date = $request->input('acquisition_date');
            $property->acquisition_method = $request->input('acquisition_method');
            if($request->has('assignment_date'))
                $property->assignment_date = $request->input('assignment_date');
            $property->bank_conversion_date = $request->input('bank_conversion_date');
            $property->ai_reject_date = $request->input('ai_reject_date');

            $property->value_gross_book = $request->input("value_gross_book");
            $property->value_gross_approved = $request->input("value_gross_approved");
            $property->budget_current_period_length = $request->input("budget_current_period_length");
            $property->budget_current_period_start_date = $request->input("budget_current_period_start_date");
            $property->budget_current_period_end_date = $request->input("budget_current_period_end_date");

            $property->tax_property_id = $request->input("tax_property_id");
        }

        // AMENITIES TAB
        if(Auth::user()->can('amenities_tab_edit')) {

            // INDIVIDUAL AMENITIES SYNCED AFTER SAVE()
            $property->amenity_notes = $request->input("amenity_notes");

        }

        // NET EQUITY TAB
        if(Auth::user()->can('net_equity_tab_edit')) {
            $property->net_equity_recommendation_note = $request->input("net_equity_recommendation_note");
            $property->net_equity_executive_summary_note = $request->input("net_equity_executive_summary_note");
            $property->net_equity_title_comments_note = $request->input("net_equity_title_comments_note");
            $property->net_equity_property_expenses_note = $request->input("net_equity_property_expenses_note");
            $property->net_equity_sales_related_expenses_note = $request->input("net_equity_sales_related_expenses_note");
            $property->net_equity_prepared_by = $request->input("net_equity_prepared_by");
            $property->net_equity_prepared_date = $request->input("net_equity_prepared_date");
            $property->net_equity_prepared_by_note = $request->input("net_equity_prepared_by_note");
        }

        // BUDGET TAB
        $generateBudget = false; // THIS NEEDS A DEFAULT VALUE IF THE USER DOESN'T HAVE EDIT ACCESS TO THE BUDGET
        if(Auth::user()->can('budget_tab_edit')) {
            $property->budget_case_number = $request->input("budget_case_number");
            $property->budget_current_period_length = $request->input("budget_current_period_length");
            $property->budget_current_period_start_date = $request->input("budget_current_period_start_date");
            $property->budget_current_period_end_date = $request->input("budget_current_period_end_date");
            $property->budget_receiver_subsidiary = $request->input("budget_receiver_subsidiary");

            $property->budget_json = $request->input("budget_json");

            $property->budget_prepared_date = $request->input("budget_prepared_date");
            $property->budget_prepared_by = $request->input("budget_prepared_by");
            $property->budget_submitted_date = $request->input("budget_submitted_date");
            $property->budget_submitted_by = $request->input("budget_submitted_by");
            $property->budget_approved_date = $request->input("budget_approved_date");
            $property->budget_approved_by = $request->input("budget_approved_by");
            $property->budget_concurred_date = $request->input("budget_concurred_date");
            $property->budget_concurred_by = $request->input("budget_concurred_by");

            $property->budget_expenditures_prior_to_assignment = $request->input('budget_expenditures_prior_to_assignment');

            $property->budgetUpkeep();

            if($request->has("budget_state")) {
                if ( ($request->input("budget_state") != 'draft') && ($property->budget_phase != $request->input("budget_phase") || $property->budget_state != $request->input("budget_state")) && config('app.client') != 'USMS')
                    $generateBudget = true;
            }

            $property->budget_phase = $request->input("budget_phase");
            $property->budget_state = $request->input("budget_state");

        }

        // DISPOSITION TAB
        if(Auth::user()->can('disposition_tab_edit')) {
            $property->financial_settlement_date_projected = $request->input("financial_settlement_date_projected");
            $property->financial_sales_contract_offer_amount = $request->input("financial_sales_contract_offer_amount");
            $property->financial_sales_contract_offer_accepted_date = $request->input("financial_sales_contract_offer_accepted_date");
            $property->financial_settlement_date = $request->input("financial_settlement_date");
            $property->financial_partial_sale = $request->input("financial_partial_sale");
            // TODO: remove //$property->financial_buyer_first_name = $request->input("financial_buyer_first_name");
            // TODO: remove $property->financial_buyer_last_name = $request->input("financial_buyer_last_name");
            // TODO: remove $property->financial_minority_or_woman_owned = $request->input("financial_minority_or_woman_owned");
            $property->financial_sales_gross_amount = $request->input("financial_sales_gross_amount");
            $property->financial_cost_of_real_estate_commissions = $request->input("financial_cost_of_real_estate_commissions");
            $property->financial_sales_cost = $request->input("financial_sales_cost");
            // TODO: remove $property->financial_wire_amount = $request->input("financial_wire_amount");
            // TODO: remove $property->financial_wire_date = $request->input("financial_wire_date");
            // TODO: remove $property->financial_incentive_date = $request->input("financial_incentive_date");
            // TODO: remove $property->financial_disposition_fee_paid_out_of_closing = $request->input("financial_disposition_fee_paid_out_of_closing");
            // TODO: remove $property->financial_shared_with_buyers_broker = $request->input("financial_shared_with_buyers_broker");
            $property->financial_boarding_fees = $request->input("financial_boarding_fees");
            $property->financial_asset_management_fee = $request->input("financial_asset_management_fee");
            $property->financial_security_deposit = $request->input("financial_security_deposit");

            $property->purchasers = $request->input("purchasers");

            if(Auth::user()->can(['properties_view_commission']))
                $property->commissions_json = $request->input("commissions_json");

        }

        // ENVIRONMENTAL TAB
        if(Auth::user()->can('environmental_tab_edit')) {

            $property->environmental_flag = $request->input("environmental_flag");
            $property->environmental_flood_zone = $request->input("environmental_flood_zone");

            if (isset($request->environmental_codes) && $request->environmental_codes != "") {
                if (is_array($request->environmental_codes)) {
                    $property->environmental_codes = implode(",", $request->environmental_codes);
                } else {
                    $property->environmental_codes = $request->environmental_codes;
                }
            } else {
                $property->environmental_codes = "";
            }

            if(isset($request->environmental_special_resource_codes) && $request->environmental_special_resource_codes != "") {
                if(is_array($request->environmental_special_resource_codes)) {
                    $property->environmental_special_resource_codes = implode(",", $request->environmental_special_resource_codes);
                }else{
                    $property->environmental_special_resource_codes = $request->environmental_special_resource_codes;
                }
            }else{
                $property->environmental_special_resource_codes = "";
            }

            $property->environmental_codes_LDBP = $request->input("environmental_codes_LDBP");
            $property->environmental_codes_comments = $request->input("environmental_codes_comments");
            $property->environmental_special_resource_codes_comments = $request->input("environmental_special_resource_codes_comments");
            $property->environmental_ordered_date = $request->input("environmental_ordered_date");
            $property->environmental_report_type = $request->input("environmental_report_type");
            $property->environmental_report_date = $request->input("environmental_report_date");
            $property->environmental_action_comment = $request->input("environmental_action_comment");
            $property->environmental_last_inspection_date = $request->input("environmental_last_inspection_date");
            $property->environmental_memo_date = $request->input("environmental_memo_date");
            $property->environmental_action_completion_date = $request->input("environmental_action_completion_date");
            $property->environmental_survey_ordered_date = $request->input("environmental_survey_ordered_date");
            $property->environmental_survey_date = $request->input("environmental_survey_date");
            $property->environmental_survey_review_date = $request->input("environmental_survey_review_date");
            $property->environmental_survey_notes = $request->input("environmental_survey_notes");

        }

        // LEGAL TAB
        if(Auth::user()->can('legal_tab_edit')) {
            $property->legal_issues = $request->input("legal_issues");
            $property->legal_case_caption = $request->input("legal_case_caption");
            $property->legal_type = $request->input("legal_type");
            $property->legal_other_lien_issues = $request->input("legal_other_lien_issues");
            $property->legal_matter_closed = $request->input("legal_matter_closed");
            $property->legal_critical_action_required_description = $request->input("legal_critical_action_required_description");
            $property->legal_claims_notified = $request->input("legal_claims_notified");
            $property->legal_cash_damages_by_borrower = $request->input("legal_cash_damages_by_borrower");
            $property->legal_description = $request->input("legal_description");
            $property->legal_case_name = $request->input("legal_case_name");
            $property->legal_court = $request->input("legal_court","");
            $property->legal_docket = $request->input("legal_docket","");
            $property->legal_servicer_legal_matter_id = $request->input("legal_servicer_legal_matter_id");
            $property->legal_modification_flag = $request->input("legal_modification_flag");
            $property->legal_foreclosure = $request->input("legal_foreclosure");
            $property->legal_foreclosure_type = $request->input("legal_foreclosure_type");
            $property->legal_foreclosure_status = $request->input("legal_foreclosure_status");
            $property->legal_one_action_state = $request->input("legal_one_action_state");
            $property->legal_redemption_right = $request->input("legal_redemption_right");
            $property->legal_bankruptcy = $request->input("legal_bankruptcy");
            $property->legal_bankruptcy_chapter = $request->input("legal_bankruptcy_chapter");
            $property->legal_bankruptcy_trustee = $request->input("legal_bankruptcy_trustee");
            $property->legal_bankruptcy_status = $request->input("legal_bankruptcy_status");
            $property->legal_other_litigation = $request->input("legal_other_litigation");
            $property->legal_outside_counsel_retained = $request->input("legal_outside_counsel_retained");
            $property->legal_inherited_counsel = $request->input("legal_inherited_counsel");
            $property->legal_outside_counsel_fees_to_date = $request->input('legal_outside_counsel_fees_to_date');
            $property->legal_next_critical_action_date = $request->input('legal_next_critical_action_date');
            $property->legal_date_matter_opened_filed = $request->input('legal_date_matter_opened_filed');
            $property->legal_modification_date = $request->input('legal_modification_date');
            $property->legal_redemption_date = $request->input('legal_redemption_date');
        }

        // LIENS TAB
        if(Auth::user()->can('liens_tab_edit')) {
            $property->financial_liens = $request->input("financial_liens");
            $property->financial_total_liens = $request->input('financial_total_liens');
        }

        // MARKETING TAB
        if(Auth::user()->can('marketing_tab_edit')) {
            if(Auth::user()->can('properties_publish_to_marketing'))
                $property->marketing_published = $request->input("marketing_published");

            $property->status_primary = $request->input("status_primary");
            $property->status_secondary = $request->input("status_secondary");
            $property->status_tertiary = $request->input("status_tertiary");
            $property->marketing_strategy_code = $request->input("marketing_strategy_code");
            $property->marketing_event_code = $request->input("marketing_event_code");
            $property->unmarketable = $request->input("unmarketable");
            $property->marketing_unmarketable_start_date = $request->input("marketing_unmarketable_start_date");
            $property->marketing_unmarketable_end_date = $request->input("marketing_unmarketable_end_date");
            $property->unmarketable_reason = $request->input("unmarketable_reason");
            $property->marketing_site_description = $request->input("marketing_site_description");
            $property->number_of_beds = $request->input('number_of_beds');
            $property->number_of_baths = $request->input("number_of_baths");
            $property->year_built = $request->input("year_built");

            // MARKETING START DATE IS NOW A PROTECTED, SEMI-AUTOMATED FIELD.
            // IF NULL, IT WILL BE SET TO THE FIRST ENTRY IN THE MARKETING POLLER FOR THIS ASSET.
            //$property->marketing_start_date = $request->input("marketing_start_date");
            /*
            $poller_history = json_decode($property->marketing_site_polling_history, true);
            if(!$property->marketing_start_date) {
                if($poller_history)
                    $property->marketing_start_date = end($poller_history)['Date'];
            }*/
            // 2019-12-12 PER BT - MARKETING START DATE NOW SET WHENEVER A PROPERTY OR ASSOCIATED 949609 ORDER LINE ITEM IS SAVED. SEE: $proeprty->getMarketingStartDate()

            // 2019-09-05 - NKH: NOW HANDLED VIA THE POLLER
            //$property->marketing_days_on_market = $request->input("marketing_days_on_market");
            $property->marketing_listing_start_date = $request->input("marketing_listing_start_date");
            $property->marketing_listing_end_date = $request->input("marketing_listing_end_date");
            $property->marketing_signage = $request->input("marketing_signage");
            $property->marketing_mls = $request->input("marketing_mls");
            $property->financial_current_list_price = $request->input("financial_current_list_price");
            $property->financial_current_list_price_date = $request->input("financial_current_list_price_date");
            $property->financial_initial_list_price = $request->input("financial_initial_list_price");
            $property->financial_list_price_history = $request->input("financial_list_price_history");
            $property->marketing_external_url = $request->input("marketing_external_url");
            $property->marketing_brochure_date = $request->input("marketing_brochure_date");
            $property->marketing_current_month_showings = $request->input("marketing_current_month_showings");
            $property->marketing_cumulative_showings = $request->input("marketing_cumulative_showings");

            $property->marketing_number_of_offers = $request->input("marketing_number_of_offers");
            $property->marketing_last_showing_date = $request->input("marketing_last_showing_date");
            $property->marketing_offer_history = $request->input("marketing_offer_history");

            $property->marketing_affiliated_broker = $request->input("marketing_affiliated_broker");
            $property->marketing_brokers_commission = $request->input("marketing_brokers_commission");
            $property->marketing_brokers_id = $request->input("marketing_brokers_id");
            $property->marketing_notes = $request->input("marketing_notes");

        }

        // PROPERTY MANAGEMENT TAB
        if(Auth::user()->can('property_management_tab_edit')) {
            $property->property_management_occupancy_status = $request->input("property_management_occupancy_status");
            $property->property_management_leased_space = $request->input("property_management_leased_space");
            $property->property_management_om_approval_date = $request->input("property_management_om_approval_date");
            $property->property_management_approval_for_3ppm = $request->input("property_management_approval_for_3ppm");
            $property->property_management_reason_for_3ppm = $request->input("property_management_reason_for_3ppm");
            $property->property_management_notes_for_3ppm = $request->input("property_management_notes_for_3ppm");
            $property->property_management_effective_date = $request->input("property_management_effective_date");
            $property->property_management_expiration_date = $request->input("property_management_expiration_date");

//            $property->property_management_inspections = $request->input("property_management_inspections");
            $property->property_management_inspections = $this->syncPropertyInspections(
                $property,
                $request->input("property_management_inspections")
            );

            // TODO: remove
            //$property->property_management_custody_inspection_company = $request->input("property_management_custody_inspection_company");
            //$property->property_management_custody_inspection_name = $request->input("property_management_custody_inspection_name");
            //$property->property_management_custody_inspection_date = $request->input("property_management_custody_inspection_date");
            //$property->property_management_last_inspection_date = $request->input("property_management_last_inspection_date");
            //$property->property_management_last_inspection_on_file = $request->input("property_management_last_inspection_on_file");

            $property->property_management_bank_premise_cleared = $request->input("property_management_bank_premise_cleared");
            $property->property_management_regulation_jurisdiction = $request->input("property_management_regulation_jurisdiction");

            $property->marketing_lockbox_code = $request->input("marketing_lockbox_code");
            $property->marketing_lockbox_description = $request->input("marketing_lockbox_description");

            $property->property_management_lockbox_info = $request->input("property_management_lockbox_info");

            $property->property_management_notes = $request->input("property_management_notes");

            $property->property_management_maintenance_and_repairs = $request->input("property_management_maintenance_and_repairs");
            $property->property_management_provider_list = $request->input("property_management_provider_list");
            $property->property_management_violations = $request->input("property_management_violations");

            $property->violation_description = $request->input("violation_description");
            $property->violation_notice_date = $request->input("violation_notice_date");
            $property->violation_status = $request->input("violation_status");
            $property->violation_reason = $request->input("violation_reason");
            $property->violation_noticing_entity = $request->input("violation_noticing_entity");
            $property->violation_remedy_start_date = $request->input("violation_remedy_start_date");
            $property->violation_remedy = $request->input("violation_remedy");
        }

        // TAX TAB
        if(Auth::user()->can('tax_tab_edit')) {
            $property->tax_total_due = $request->input("tax_total_due");
            $property->tax_current_amount_due = $request->input("tax_current_amount_due");
            $property->tax_current_year = $request->input("tax_current_year");
            $property->tax_previous_amount_due = $request->input("tax_previous_amount_due");
            $property->tax_previous_year = $request->input("tax_previous_year");
            $property->tax_due_date = $request->input("tax_due_date");
            $property->tax_past_due_amount = $request->input("tax_past_due_amount");
            $property->tax_paid_date = $request->input("tax_paid_date");
            $property->tax_status = $request->input("tax_status");
            $property->tax_jurisdiction = $request->input("tax_jurisdiction");
            $property->tax_property_id = $request->input("tax_property_id");
            $property->tax_protested = $request->input("tax_protested");
            $property->tax_protested_date = $request->input("tax_protested_date");
            $property->tax_foreclosure_date = $request->input("tax_foreclosure_date");
            $property->value_land = $request->input("value_land");
            $property->value_tax_assessed = $request->input("value_tax_assessed");
            $property->value_net_tax_assessed = $request->input("value_net_tax_assessed");
            $property->tax_notes = $request->input("tax_notes");
        }

        // TITLE TAB
        if(Auth::user()->can('title_tab_edit')) {

            $property->preseizure_title_report = $request->input('preseizure_title_report');
            $property->preseizure_title_report_effective_date = $request->input('preseizure_title_report_effective_date');
            $property->preseizure_title_report_ordered_date = $request->input('preseizure_title_report_ordered_date');
            $property->preseizure_title_report_received_date = $request->input('preseizure_title_report_received_date');
            $property->preseizure_deed_date = $request->input('preseizure_deed_date');
            $property->preseizure_deed_type = $request->input('preseizure_deed_type');
            $property->preseizure_deed_of_record_date = $request->input('preseizure_deed_of_record_date');
            $property->preseizure_rerecording_date = $request->input('preseizure_rerecording_date');
            $property->preseizure_owners_identified_by_ia_usao = $request->input('preseizure_owners_identified_by_ia_usao');
            $property->preseizure_name_on_title = $request->input('preseizure_name_on_title');
            $property->preseizure_association_dues = $request->input('preseizure_association_dues');
            $property->preseizure_sold_at_pending_tax_sale_date = $request->input('preseizure_sold_at_pending_tax_sale_date');
            $property->preseizure_foreclosure_proceeding_bank = $request->input('preseizure_foreclosure_proceeding_bank');
            $property->preseizure_foreclosure_proceeding_date = $request->input('preseizure_foreclosure_proceeding_date');
            $property->preseizure_condition_report_title_notes = $request->input('preseizure_condition_report_title_notes');
            $property->preseizure_new_title_notes = $request->input('preseizure_new_title_notes');

            $property->title_report = $request->input('title_report');
            $property->title_effective_date = $request->input('title_effective_date');
            $property->title_ordered_date = $request->input('title_ordered_date');
            $property->title_received_date = $request->input('title_received_date');
            $property->title_status = $request->input('title_status');
            $property->title_cleared_date = $request->input('title_cleared_date');
            $property->title_legal_description_validation_date = $request->input('title_legal_description_validation_date');
            $property->title_revision_date = $request->input('title_revision_date');
            $property->title_commitment_number = $request->input('title_commitment_number');
            $property->title_name_on_title = $request->input('title_name_on_title');
            $property->financial_total_liens = $request->input('financial_total_liens');
            $property->environmental_survey_date = $request->input('environmental_survey_date');
            $property->title_referred_to_legal = $request->input('title_referred_to_legal');
            $property->title_referred_to_legal_date = $request->input('title_referred_to_legal_date');
            $property->title_billed = $request->input('title_billed');
            $property->title_invoice_date = $request->input('title_invoice_date');
            $property->title_invoice_number = $request->input('title_invoice_number');
            $property->title_notes = $request->input('title_notes');
        }

        // VALUATION TAB
        if(Auth::user()->can('valuation_tab_edit')) {
            $property->appraisal1_type = $request->input("appraisal1_type");
            $property->appraisal2_type = $request->input("appraisal2_type");
            $property->appraisal1_notes = $request->input("appraisal1_notes");
            $property->appraisal1_ffe_comments = $request->input("appraisal1_ffe_comments");
            $property->appraisal2_notes = $request->input("appraisal2_notes");
            $property->appraisal2_ffe_comments = $request->input("appraisal2_ffe_comments");
            $property->value_current_appraised = $request->input("value_current_appraised");
            $property->value_first_appraised = $request->input("value_first_appraised");
            $property->value_second_appraised = $request->input("value_second_appraised");
            $property->value_gross_approved = $request->input("value_gross_approved");
            $property->appraisal1_value = $request->input("appraisal1_value");
            $property->appraisal1_ffe = $request->input("appraisal1_ffe");
            $property->appraisal2_value = $request->input("appraisal2_value");
            $property->appraisal2_ffe = $request->input("appraisal2_ffe");
            $property->value_current_appraised_date = $request->input('value_current_appraised_date');
            $property->value_first_appraised_date = $request->input('value_first_appraised_date');
            $property->value_second_appraised_date = $request->input('value_second_appraised_date');
            $property->appraisal1_effective_date = $request->input('appraisal1_effective_date');
            $property->appraisal1_ordered_date = $request->input('appraisal1_ordered_date');
            $property->appraisal1_review_date = $request->input('appraisal1_review_date');
            $property->appraisal2_effective_date = $request->input('appraisal2_effective_date');
            $property->appraisal2_ordered_date = $request->input('appraisal2_ordered_date');
            $property->appraisal2_review_date = $request->input('appraisal2_review_date');
            $property->appraisal2_date = $request->input('appraisal2_date');
            $property->appraisal1_date = $request->input('appraisal1_date');
            $property->value_net_approved_value = $request->input("value_net_approved_value");
            $property->value_net_book = $request->input("value_net_book");

            $property->participation_log = $request->input("participation_log");
            $property->participation_lead_participant = $request->input("participation_lead_participant");
            $property->participation_other_participants = $request->input("participation_other_participants");
            $property->participation_percentage_owned = $request->input("participation_percentage_owned");
            $property->participation_number_of_participants = $request->input("participation_number_of_participants");

            $property->valuation_notes = $request->input("valuation_notes");
        }

        // VALUATION TAB
        //if(Auth::user()->can('valuation_tab_edit')) {
        //    $property->marketing_notes = $request->input("marketing_notes");
        //}

        // CLIENT_NOTES TAB
        if(Auth::user()->can('client_notes_tab_edit')) {
            $property->client_notes = $request->input("client_notes");
        }

        $property->marketing_start_date = $property->getMarketingStartDate();

        $property->updated_by = Auth::user()->id;

        try {
            $property->save();
        } catch (\Exception $e) {
            $this->log->exception($e);
            return redirect()->back()->with('error', 'There was a problem editing \'' . $property->getCode() . '\'. Please contact your system administrator.');
        }

        // UPDATE PROPERTY PHASE AND SUB-PHASE
        $property->setPhases();

        // PROPERTY HAS MOVED FROM ACTIVE TO INACTIVE - CLOSE OUT THE FOLLOWING CLINS:
        //    "784562","498657","879654","465923","995412","569814","223648","845796","134975","112598","134687","622501","949609","795615"
        //    financial_settlement_date
        if($set_default_end_dates) {
            //$clins = OrderLineItem::whereIn('code', ["784562","498657","879654","465923","995412","569814","223648","845796","134975","112598","134687","622501","949609","795615"])
            //                      ->whereNull('completed_at')
            //                      ->update(['status' => '9', 'updated_by' => Auth::user()->id, 'completed_at' => $property->financial_settlement_date, 'history[]' => '{test}']);

            $clins = OrderLineItem::whereIn('code', ["784562","498657","879654","465923","995412","569814","223648","845796","134975","112598","134687","622501","949609","795615"])
                                  ->whereNull('completed_at')
                                  ->with(['order'])
                                  //->with(['order' => function ($query) use ($property) {
                                  //    $query->where('property_id', $property->id);
                                  //}])
                                  ->get();

            $clins = $clins->filter(function ($clin) use ($property) {
                return $clin->order->property_id == $property->id;
            });

            $line_item_statuses = DB::table('codes_descriptions')->where('type', 'order_line_item_status')->get();
            $line_item_statuses = $line_item_statuses->mapWithKeys(function ($line_item_status, $key) {
                return [$line_item_status->code => $line_item_status];
            });

            $reason_for_change = "Asset status set to Inactive, settlement date ".$property->financial_settlement_date;

            foreach($clins as $clin) {
                if(in_array($clin->status, ['5', '6', '9', '10', '12', '13'])) {
                    continue;
                }
                $clin->updated_by = Auth::user()->id;

                if(!in_array($clin->status, ['1', '2', '3'])) {
                    $clin->status_previous = $clin->status;
                    $clin->status = '9';
                }
                $clin->completed_at = $property->financial_settlement_date;

                if($clin->code == 949609) {
                    $clin->billed_at = $property->financial_settlement_date;
                    $clin->sent_at = $property->financial_settlement_date;
                }

                $history = json_decode($clin->history, true);
                if (!$history)
                    $history = array();

                $new = array(
                    "Date" => Carbon::now()->toDateTimeString(),
                    "User" => Auth::user()->username,
                    "Verb" => 'update',
                    "Assigned To" => $clin->getAssignedTo(),
                    "Description" => $clin->code . ' | ' . $clin->contract_line_item->description,
                    "Number" => $clin->number,
                    "Cost" => $clin->getCost(),
                    "Status" => $line_item_statuses[$clin->status]->description,
                    "Action" => $clin->action,
                    "Comment" => $reason_for_change
                );
                array_push($history, $new);
                $clin->history = json_encode($history);
                try {
                    $clin->save();
                } catch (\Exception $e) {
                    $this->log->exception($e);
                }
            }
        }

        if($generateBudget) {
            $excel = $property->generateBudget();
            $destination = $property->getPath() . "/Six Part File/2-Budget & Cases/Budgets";
            $excel->store('xls', $destination);
            $this->log->write('info', 'upload', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'upload->type' => 'budget'
                ]
            ]);
            Artisan::call('owncloud:refresh', ['path' => $destination]);
        }

        // SYNC AMENITIES WITH THE amenity_property TABLE

        if(Auth::user()->can('amenities_tab_edit')) {

            $amenities = array(); // LEAVE THIS BLANK IF THE USER DOESN'T HAVE PERMISSIONS, SO WE AT LEAST KNOW THEY AREN'T TOUCHING THE AMENITIES

            if($request->input("amenities")) {
                foreach ($request->input("amenities") as $key => $value) {

                    // insert amenity into array to pass to intersection table

                    if ($request->has('amenity-' . $value . "-subtype"))
                        $subtype = $request->input('amenity-' . $value . "-subtype");
                    else
                        $subtype = null;

                    $amenities[] = ['amenity_id' => $value, 'subtype' => $subtype];

                }
            }

            $property->amenities()->detach();
            $property->amenities()->sync($amenities);
        }


        // SYNC "Contacts" TAB WITH contact_property TABLE
        if(Auth::user()->can('contacts_tab_edit')) {

            if(Route::currentRouteName() == 'postAddProperty') {
                $contacts = $property->getDefaultContacts();
            }
            else {
                $contacts = array();
                foreach ($request->input('contacts') as $contact_type_id => $contact_id) {
                    if ($contact_id) {
                        $contacts[] = ['contact_id' => $contact_id, 'contact_type_id' => $contact_type_id];

                        $contact = Contact::find($contact_id);
                        if($contact->user) {
                            if ($contact_type_id == 1)
                                $clins = [760604, 654895, 769321, 877144, 930744, 366987, 944453, 304738, 699569, 129546, 269471, 722071, 549886, 345987, 795416, 765043, 548956, 179654, 664731, 376462];
                            elseif($contact_type_id == 13)
                                $clins = [622501, 949609, 795615];
                            elseif($contact_type_id == 29)
                                $clins = [498423, 596416, 663148];

                            // 2019-06-18 - re-enabled per BT
                            elseif($contact_type_id == 27)
                                $clins = [456987];
                            elseif($contact_type_id == 25)
                                $clins = [188896, 614050, 784562, 498657, 879654, 465923, 995412, 569814, 223648, 845796, 134975, 112598, 134687, 389746];
                            elseif($contact_type_id == 26)
                                $clins = [413868];
                            else
                                $clins = [];

                            $count = DB::table('order_line_items')
                                ->join('orders', 'orders.id', '=', 'order_line_items.order_id')
                                ->where(function($q) use($contact) {
                                    $q->where('assigned_to', '<>', $contact->user->id)
                                      ->orWhereNull('assigned_to');
                                })
                                //->where('assigned_to', '<>', $contact->user->id)
                                //->orWhereNull('assigned_to')
                                ->whereIn('order_line_items.code', $clins)
                                ->whereIn('order_line_items.status', [1, 2, 3, 4, 7, 8])
                                ->whereNull('order_line_items.deleted_at')
                                ->where('orders.property_id', $property->id)
                                ->whereNull('orders.deleted_at')
                                ->update(['order_line_items.updated_at' => now(), 'assigned_to' => $contact->user->id]);

                            if ($count > 0)
                                $this->log->write('info', 'task reassignment', [
                                    'id' => $property->id,
                                    'metadata' => [
                                        'property->code' => $property->code,
                                        'property->code_temp' => $property->code_temp,
                                        'contact_id' => $contact_id,
                                        'contact_type_id' => $contact_type_id,
                                        'user_id' => $contact->user->id,
                                        'tasks updated' => $count,
                                        'targeted clins' => $clins
                                    ]
                                ]);
                        }

                    }

                }

                // IGNORE CONTACT TYPES NOT LISTED ON THE CONTACTS TAB - THEY WILL (SHOULD) BE HANDLED SEPARATELY
                //$property->contacts()->detach();

                $contact_tab_types = ContactType::where('show_on_contacts_tab', 1)->pluck('id')->toArray();
                $property->contacts()->wherePivotIn('contact_type_id', $contact_tab_types)->detach();
            }
            $property->contacts()->syncWithoutDetaching($contacts);

            $this->log->write('info', 'contact sync', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'contacts' => $contacts
                ]
            ]);
        }

        // SYNC INSPECTIONS LOG WITH contact_property TABLE
        if(Auth::user()->can('property_management_tab_edit')) {

            if($property->property_management_inspections) {

                $inspectors = array();
                $inspections = json_decode($property->property_management_inspections, true);
                foreach ($inspections as $inspection) {
                    if ($inspection["Contact ID"]) {

                        // CHECK FOR DUPLICATES
                        if(!in_array($inspection["Contact ID"], array_column($inspectors, 'contact_id')))
                            $inspectors[] = ['contact_id' => $inspection["Contact ID"], 'contact_type_id' => 11];

                    }
                }

                // 11 - property-inspector
                $property->contacts()->wherePivot('contact_type_id', 11)->detach();

                $property->contacts()->syncWithoutDetaching($inspectors);

                $this->log->write('info', 'inspector sync', [
                    'id' => $property->id,
                    'metadata' => [
                        'property->code' => $property->code,
                        'property->code_temp' => $property->code_temp,
                        'inspectors' => $inspectors
                    ]
                ]);

            }

        }

        // FIELD CHANGE THRESHOLD
        $denominator = count($property->toArray());
        $numerator = count($changed);
        $percentage = round(($numerator / $denominator) * 100);


        if($percentage >= config('app.alert_threshold'))
            $this->helpers->send_alert($property, null, null,'['.config('app.name').'] '.$percentage.'% Edit Threshold Alert', 'Edit threshold: '.config('app.alert_threshold').'%<br />Recent changes: '.$percentage.'%<br /><br />'.implode("<br />", $changed));

        // IF THE CODE CHANGED, UPDATE THE DIRECTORY STRUCTURE TO MATCH
        if($property->code) {
            $new = $property->code;
            if($old_code)
                $old = $old_code;
            else
                $old = $old_code_temp;
        }
        else {
            $new = $property->code_temp;
            if($old_code)
                $old = $old_code;
            else
                $old = $old_code_temp;

        }

        if($new != $old) {

            if(!$this->helpers->rename($property->getBase().$old, $property->getPath()))
                $request->session()->flash('warning', 'There was a problem renaming this properties file structure. Please contact your system administrator.');

        }

        // IF THE GROUP CHANGED, UPDATE THE DIRECTORY STRUCTURE TO MATCH
        if($property->group_code != $old_group_code) {
            $old_path = config('app.filestore').config('app.owncloud')."Groups".DIRECTORY_SEPARATOR.$old_group_code.DIRECTORY_SEPARATOR."Assets".DIRECTORY_SEPARATOR;

            if (!$this->helpers->rename($old_path.$property->getCode(), $property->getPath()))
                $request->session()->flash('warning', 'There was a problem renaming this properties file structure. Please contact your system administrator.');

            Artisan::call('owncloud:refresh', ['path' => $old_path]);

        }

        // CHECK FILE STRUCTURE AND CREATE ANY MISSING FOLDERS
        if(!$this->helpers->create_directories($property->getPath(), $this->directory_structure))
            $request->session()->flash('warning', 'There was a problem creating this properties file structure. Please contact your system administrator.');

        if($message == 'create')
            return redirect('properties/edit/'.$property->id)->with('success', '\'' . $property->getCode() . '\' has been successfully added.');
        else
            return redirect('properties/edit/'.$property->id)->with('success', '\'' . $property->getCode() . '\' has been successfully modified.');

    }

    public function addProperty() {

        try {

            $contactTypes = ContactType::where('show_on_contacts_tab',1)->orderBy('type')->get();
            $contacts = array();
            $contactTypeDropdown = array();
            foreach ($contactTypes as $contactType) {

                $contacts[$contactType->id]['name'] = null;
                $contacts[$contactType->id]['organization'] = null;
                $contacts[$contactType->id]['phone'] = null;
                $contacts[$contactType->id]['email'] = null;
                $contacts[$contactType->id]['address1'] = null;
                $contacts[$contactType->id]['address2'] = null;
                $contacts[$contactType->id]['city'] = null;
                $contacts[$contactType->id]['state'] = null;
                $contacts[$contactType->id]['zip'] = null;
                $contacts[$contactType->id]['id'] = null;

                $contactTypeDropdown[$contactType->id] = Contact::where('type', 1)->whereHas('types', function ($query) use ($contactType) {
                    $query->where('contact_type_id', $contactType->id);
                })->get();

                $contactTypeDropdown[$contactType->id] = $contactTypeDropdown[$contactType->id]->sortBy(function ($contact) {
                    return $contact->getName();
                });
            }

            $inspection_contacts = Contact::where('type', 1)->where('disabled', 0)->whereHas('types', function ($query) {
                $query->where('contact_type_id', 11);
            })->orderBy('first_name')->get();

            $groups = Group::orderBy('name')->get();
            foreach($groups as $index => $group) {
                $cascade = '';
                if(sizeOf($group->contracts) > 0) {
                    $cascade = implode(",", $group->contracts->pluck('task_order_number')->toArray());
                }
                $groups[$index]['cascade'] = $cascade;
            }

            $contracts = Contract::all();

            $property_types = DB::table('codes_descriptions')->where('type','property_type')->get();
            $property_sub_types = DB::table('codes_descriptions')->where('type','property_sub_type')->get();
            $property_collateral_types = DB::table('codes_descriptions')->where('type','property_collateral_type')->get();

            $occupancy_status = DB::table('codes_descriptions')->where('type','occupancy_status')->orderBy('id', 'desc')->get();
            $status_secondary = DB::table('codes_descriptions')->where('type','status_secondary')->orderBy('code')->get();
            $status_tertiary = DB::table('codes_descriptions')->where('type','status_tertiary')->get();

            $status_primary = DB::table('codes_descriptions')->where('type','status_primary')->get();

            $environmental_codes = DB::table('codes_descriptions')->where('type', '=', 'environmental')->get();
            $special_resource_codes = DB::table('codes_descriptions')->where('type','=','special_resource')->get();
            $environmental_report_types = DB::table('codes_descriptions')->where('type','=','environmental_report_type')->get();

            $litigation_codes = DB::table('codes_descriptions')->where('type', '=', 'litigation')->get();

            $marketing_strategy_codes = DB::table('codes_descriptions')->where('type', '=', 'marketing_strategy_code')->get();

            $title_report_descriptions = DB::table('codes_descriptions')->where('type','=','title_report')->get();
            $title_status_descriptions = DB::table('codes_descriptions')->where('type','=','title_status')->get();

            $tax_status_descriptions = DB::table('codes_descriptions')->where('type','=','tax_status')->get();

            $property_management_reason_for_3ppm_descriptions = DB::table('codes_descriptions')->where('type','=','reason_for_3ppm')->get();

            $lien_types = DB::table('codes_descriptions')->where('type','=','lien_type')->get();
            $provider_list_types = DB::table('codes_descriptions')->where('type','=','provider_list_type')->get();
            $violation_statuses = DB::table('codes_descriptions')->where('type','=','violation_status')->get();

            $appraisal_types = DB::table('codes_descriptions')->where('type', '=', 'appraisal_type')->orderBy('description')->get();
            $acquisition_methods = DB::table('codes_descriptions')->where('type', '=', 'acquisition_method')->get();

            $budget = [];

            $accounting_codes = DB::table('codes_descriptions')->where('type','accounting_code')-> orderBy('description')->get()->toArray();
            foreach($accounting_codes as $accounting_code) {

                $code = $accounting_code->code;
                $description = $accounting_code->description;

                if(in_array($code, array('4300', '4305', '4500'))) {
                    $section = 'INCOME';
                }
                elseif(in_array($code, array('5210', '5522', '5405', '5415', '5411', '5440', '5255', '5524', '5413', '5412', '5430', '5204', '5410', '5416', '5500', '5414'))) {
                    $section = 'OPERATING EXPENSES';
                }
                elseif(in_array($code, array('5523', '5258', '5252', '5253', '5251', '5421', '5256', '5240', '5254'))) {
                    $section = 'RESOLUTION EXPENSES';
                }
                else
                    $section = null;

                if($section)
                    $budget[$accounting_code->id] = array('section' => $section, 'code' => $code, 'description' => $description, 'prior_expenditures' => 0, 'budget_period_actuals' => 0);

            }

            $amenities = Amenity::orderBy('input')->get();

            return view('properties.add' ,
                ['mappings' => $this->mappings,
                 'contacts' => $contacts,
                 'contactTypes' => $contactTypes,
                 'contactTypeDropdown' => $contactTypeDropdown,
                 'groups' => $groups,
                 'contracts' => $contracts,
                 'property_types' => $property_types,
                 'property_sub_types' => $property_sub_types,
                 'property_collateral_types' => $property_collateral_types,
                 'occupancy_status' => $occupancy_status,
                 'status_secondary' => $status_secondary,
                 'status_tertiary' => $status_tertiary,
                 'status_primary' => $status_primary,
                 'environmental_codes' => $environmental_codes,
                 'special_resource_codes' => $special_resource_codes,
                 'environmental_report_types' => $environmental_report_types,
                 'litigation_codes' => $litigation_codes,
                 'marketing_strategy_codes' => $marketing_strategy_codes,
                 'title_report_descriptions' => $title_report_descriptions,
                 'title_status_descriptions' => $title_status_descriptions,
                 'tax_status_descriptions' => $tax_status_descriptions,
                 'property_management_reason_for_3ppm_descriptions' => $property_management_reason_for_3ppm_descriptions,
                 'lien_types' => $lien_types,
                 'poller_history' => [],
                 'provider_list_types' => $provider_list_types,
                 'violation_statuses' => $violation_statuses,
                 'acquisition_methods' => $acquisition_methods,
                 'budget_data' => [],
                 'budget_proposal' => '',
                 'appraisal_types' => $appraisal_types,
                 'tasks' => [],
                 'phase' => '',
                 'sub_phase' => '',
                 'amenities' => $amenities,
                 'property_amenities' => collect([]),
                 'inspection_contacts' => $inspection_contacts,
                 'inspection_types' => CodesDescriptions::where('type', 'inspection_type')->get(),
            ]);

        } catch(ModelNotFoundException $e) {
            return back()->with('error', 'Error adding property. Please contact your system administrator.');
        }
    }

    public function disableProperty($property) {

        // DO NOT DELETE IF IN USE
        if ($property->vendor_bills()->count() > 0)
            return redirect('vendor_bills?search='.$property->getCode())->with('error', '\'' . $property->getCode() . '\' has vendor bills associated with it and cannot be disabled. Loading vendor bills - this may take a while.');

        if ($property->orders()->count() > 0)
            return redirect('orders?search='.$property->getCode())->with('error', '\'' . $property->getCode() . '\' has orders associated with it and cannot be disabled. Loading orders - this may take a while.');

        // STORE ID AND NAME FOR MESSAGE TO USER
        try {
            $property->deleted_by = Auth::user()->id;
            $property->save();
            $property->delete();
        } catch (\Exception $e) {
            $this->log->exception($e);
            return redirect()->route('properties')->with('error', 'There was a problem disabling \'' . $property->getCode() . '\'. Please contact your system administrator.');
        }
        return redirect()->route('properties')->with('success', '\'' . $property->getCode() . '\' successfully disabled.');
    }

    public function restoreProperty($id) {

        $property = Property::withTrashed()->find($id);

        try {
            $property->restore();
            $property->deleted_by = null;
            $property->save();
        } catch (\Exception $e) {
            $this->log->exception($e);
            return redirect()->route('properties')->with('error', 'There was a problem restoring \'' . $property->getCode() . '\'. Please contact your system administrator.');
        }
        return redirect()->route('properties')->with('success', '\'' . $property->getCode() . '\' successfully restored.');
    }

    public function deleteProperty($id) {

        $property = Property::withTrashed()->find($id);

        try {
            // REMOVE CONTACT ASSOCIATIONS FOR THIS PROPERTY
            $property->contacts()->detach();


            // REMOVE VENDOR BILLS FOR THIS PROPERTY - AT THIS POINT, ALL VENDOR BILLS FOR THIS PROPERTY SHOULD BE DISABLED
            $vendor_bills = $property->vendor_bills()->withTrashed()->get();
            foreach($vendor_bills as $vendor_bill) {
                $vendor_bill->line_items()->withTrashed()->forceDelete();
            }
            $property->vendor_bills()->forceDelete();


            // REMOVE ORDERS FOR THIS PROPERTY - AT THIS POINT, ALL ORDERS FOR THIS PROPERTY SHOULD BE DISABLED
            $orders = $property->orders()->withTrashed()->get();
            foreach($orders as $order)
                $order->line_items()->withTrashed()->forceDelete();
            $property->orders()->forceDelete();


            // STORE ID AND NAME FOR MESSAGE TO USER, THEN DELETE THE PROPERTY
            $property->forceDelete();

            // REMOVE OWNCLOUD STRUCTURE
            $property = Property::withTrashed()->where('code', $property->getCode())->orwhere('code_temp', $property->getCode());
            if($property->exists()) {
                $message = 'Duplicate code/code_temp found - OwnCloud structure has been preserved.';
            }
            else {
                File::deleteDirectory($property->getPath());
                Artisan::call('owncloud:refresh', ['path' => $property->getBase()]);
                $message = 'OwnCloud structure has been wiped.';
            }
        } catch (\Exception $e) {
            $this->log->exception($e);
            return redirect()->route('properties')->with('error', 'There was a problem deleting \'' . $property->getCode() . '\'. Please contact your system administrator.');
        }
        return redirect()->route('properties')->with('success', '\'' . $property->getCode() . '\' successfully deleted. '.$message);
    }

    // THESE ARE DONE ON A PER-CONTROLLER BASIS SO THAT WE CAN LOCK DOWN THE BASE PATH, THUS PREVENTING A USER
    //    FROM JUMPING OUT OF THEIR ALLOWED RESOURCE
    public function image(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath()."/Photos";
        return $this->helpers->image($path.DIRECTORY_SEPARATOR.$name);

    }

    public function download(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath().$request->path;
        return $this->helpers->download($path.DIRECTORY_SEPARATOR.$name);

    }

    public function view(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath().$request->path;
        return $this->helpers->view($path.DIRECTORY_SEPARATOR.$name);

    }

    public function uploadFile(Request $request, $property) {

        // SUPPORTED FILE TYPE
        // https://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types
        // https://laravel.com/docs/5.5/validation#rule-mimes
        // Even though you only need to specify the extensions, this rule actually
        //     validates against the MIME type of the file by reading the file's contents and guessing its MIME type.
        if(config('app.client') == 'FDIC')
            $rules['file'] = 'required|mimes:jpg,jpeg,txt,doc,docx,xls,xlsx,ppt,pptx,pdf'; // ,gif,bmp,png
        else
            $rules['file'] = 'required|mimes:jpg,jpeg,txt,doc,docx,xls,xlsx,ppt,pptx,pdf,zip';

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json('unsupported file type', 403);
        }

        $file = $request->file;
        $path = $property->getPath().$request->path;

        return $this->helpers->upload($path, $file);

    }

    public function renameFile(Request $request, $property) {

        $old = $property->getPath().$request->old_name;
        $new = $property->getPath()."/".$request->new_name;

        if($this->helpers->rename($old, $new))
            return response()->json('success', 200);
        else
            return response()->json('error', 400);

    }

    public function deleteFile(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath().$request->path;
        return $this->helpers->delete($path, $name);

    }

    public function uploadImage(Request $request, $property) {

        // SUPPORTED FILE TYPE
        // https://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types
        // https://laravel.com/docs/5.5/validation#rule-mimes
        // Even though you only need to specify the extensions, this rule actually
        //     validates against the MIME type of the file by reading the file's contents and guessing its MIME type.
        $rules['file'] = 'required|mimes:jpg,jpeg'; // ,gif,bmp,png

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json('unsupported file type', 403);
        }

        $file = $request->file;
        $path = $property->getPath().$request->path;
        $name= $property->insertPhotos($property->id, $path, $file);

        return $this->helpers->upload($path, $file, $name);

    }

    // delete image on property photo tab . it would delete it from property_photos and add a query to property_photo_track tables
    public function deleteImage(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath()."/Photos";
        $this->helpers->delete($path, $name);

        DB::table('property_photos')->where('path', '=', $path )->where('name', $name)->delete();
        DB::table('property_photos_history')->insert([
            ['property_id' => $property->id,  'created_by' => Auth::user()->id, 'path' => $path,'name' => $name, 'type' => 'delete', 'created_at' => now()]
        ]);
    }

    // rotate image on property photo tab. it also update both property_photo and add query to property_photo_track
    public function rotateImage(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath()."/Photos";
        $degree = $request->degree;
        $this->helpers->rotate($path, $name, $degree);

        DB::table('property_photos')->where('path', '=', $path )->where('name', $name)->update(['updated_by' => Auth::user()->id, 'type' => 'rotate '.$degree, 'updated_at' => now()]);
        DB::table('property_photos_history')->insert([
            ['property_id' => $property->id,  'created_by' => Auth::user()->id, 'path' => $path,'name' => $name, 'type' => 'rotate '.$degree , 'created_at' => now()]
        ]);
    }

    // Crop image on property photo tab. it would update property_photo and make a query to property_photo_track
    public function cropImage(Request $request, $property) {

        $name = $request->name;
        $path = $property->getPath()."/Photos";
        $this->helpers->crop($path, $name, $request->image);

        DB::table('property_photos')->where('path', '=', $path )->where('name', $name)->update(['updated_by' => Auth::user()->id, 'type' => 'crop', 'updated_at' => now()]);
        DB::table('property_photos_history')->insert([
            ['property_id' => $property->id,  'created_by' => Auth::user()->id, 'path' => $path,'name' => $name, 'type' => 'crop', 'created_at' => now()]
        ]);
    }

    // undo image on property photo tab. only undo crop and rotate photo. it would update property_photo and make a query to property_photo_track
    public function revertImage(Request $request, $property) {
        $name = $request->name;
        $path = $property->getPath()."/Photos";
        $this->helpers->revert($path, $name);

        DB::table('property_photos')->where('path', '=', $path )->where('name', $name)->update(['updated_by' => Auth::user()->id, 'type' => 'undo', 'updated_at' => now()]);
        DB::table('property_photos_history')->insert([
            ['property_id' => $property->id,  'created_by' => Auth::user()->id, 'path' => $path,'name' => $name, 'type' => 'undo', 'created_at' => now()]
        ]);
    }

    public function showAmenityImage($property) {
        $amenities = DB::table('amenity_property')->where('amenity_property.property_id', '=', $property->id )
            ->join('amenities', 'amenity_property.amenity_id', '=', 'amenities.id')
            ->get();

        $photoAmenity = '<select class="form-control" id="amenity_photo_select" multiple="multiple">';

        foreach ($amenities as $amenity)
        {
            $photoAmenity .='<option value="'.$amenity->amenity_id.'"  >'.$amenity->type.'</option>';
        }
        $photoAmenity  .= '</select>';
        return $photoAmenity;
    }

    public function submitAmenityImage(Request $request, $property) {

        $name = $request->name;

        DB::table('property_photos')->where('property_id' , '=',  $property->id)->where('name', $name)
            ->update(['updated_by' => Auth::user()->id, 'type' => 'undo', 'updated_at' => now(), 'amenity_id' , $request->selectedAmenity]);
    }

    public function uploadBrochure(Request $request, $property) {

        // SUPPORTED FILE TYPE
        // https://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types
        // https://laravel.com/docs/5.5/validation#rule-mimes
        // Even though you only need to specify the extensions, this rule actually
        //     validates against the MIME type of the file by reading the file's contents and guessing its MIME type.
        $rules['file'] = 'required|mimes:pdf'; // ,gif,bmp,png

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json('unsupported file type', 403);
        }

        $file = $request->file;
        $path = $property->getPath().$request->path;

        // OVERRIDE THE SUPPLIED FILENAME - THERE CAN BE ONLY ONE BROCHURE
        return $this->helpers->upload($path, $file, "brochure.pdf");

    }

    public function deleteBrochure($property) {

        $name = "brochure.pdf";
        $path = $property->getPath()."/Marketing";
        return $this->helpers->delete($path, $name);

    }

    public function downloadBrochure($property) {

        $path = $property->getPath()."/Marketing/brochure.pdf";
        return $this->helpers->download($path, $path);

    }

    public function downloadConditionReport($property) {

        try {


            //$budget_data = $property->getBudgetData();

            //array_walk_recursive($budget_data, function (&$value) {
            //    $value = html_entity_decode($value,ENT_QUOTES,'UTF-8');
            //});


            $report = new ReportUSMS("PRE-SEIZURE CONDITION REPORT", "18-0320_USMS Pre-seizure Condition Report-Standard SFR-jd (003)");

            // METADATA
            $report->SetAuthor(config('app.pdf_author'));
            $report->SetTitle('PRE-SEIZURE CONDITION REPORT');


            // NEW PAGE
            $report->SetMargins(15, 15);
            $report->AddPage('P');
            $report->SetDisplayMode('real','default');

            $report->SetFont('Arial', '', 14);
            $report->SetFillColor(255, 255, 255);
            $report->SetTextColor(0, 0, 0);

            // ADDRESS
            $report->Cell(180,10, $property->address, 0, 0, 'C');
            $report->SetXY(15,$report->GetY()+7);
            $report->Cell(180,10, $property->city.", ".$property->state." ".$property->zip, 0, 0, 'C');
            if($property->county) {
                $report->SetXY(15,$report->GetY()+7);
                $report->Cell(180, 10, $property->county . " County", 0, 0, 'C');
            }

            $report->SetXY(15,$report->GetY()+7);


            // ASSET
            $report->SetXY(15,$report->GetY()+7);

            $report->Cell(110,10,'APN: '.$property->tax_property_id);
            $report->Cell(70,10, 'Code: '.$property->getCode());

            $report->SetXY(15,$report->GetY()+7);

            $report->Cell(110,10,'GPS: '.$property->coordinates);
            $report->Cell(70,10,'Date: '.date("F j, Y"));

            $report->SetXY(15,$report->GetY()+14);


            // HORIZONTAL RULE
            $report->SetLineWidth(.05);
            $report->Line(15,$report->GetY(),190,$report->GetY());

            $report->SetXY(15,$report->GetY()+7);

            $report->SetFont('Arial','BU',14);
            $report->Cell(180,10, "RECOMMENDATION:");

            $report->SetXY(15,$report->GetY()+10);

            $report->SetFont('Arial','B',14);
            //$report->MultiCell(180,7, stripcslashes($budget_data['NET EQUITY']['recommendation']));
            $report->MultiCell(180,7, stripcslashes($property->net_equity_recommendation_note));

            $report->SetXY(15,$report->GetY()+7);

            $report->SetFont('Arial','BU',14);
            $report->Cell(180,10, "EXECUTIVE SUMMARY:");

            $report->SetXY(15,$report->GetY()+10);

            $report->SetFont('Arial','',14);
            //$report->MultiCell(180,7, stripcslashes($budget_data['NET EQUITY']['executive_summary']));
            $report->MultiCell(180,7, stripcslashes($property->net_equity_executive_summary_note));

            $report->SetXY(15,$report->GetY()+7);

            // HORIZONTAL RULE
            $report->SetLineWidth(.05);
            $report->Line(15,$report->GetY(),190,$report->GetY());

            $report->SetXY(15,$report->GetY()+7);

            $report->SetFont('Arial','BU',14);
            $report->Cell(180,10, "COMMENTS:");

            $report->SetXY(15,$report->GetY()+10);

            $report->Cell(180,10, "TITLE - MORTGAGE LIENS/JUDGEMENT EXPENSES:");

            $report->SetXY(15,$report->GetY()+10);

            $report->SetFont('Arial','',14);
            //$report->MultiCell(180,7, stripcslashes($budget_data['NET EQUITY']['title']));
            $report->MultiCell(180,7, stripcslashes($property->net_equity_title_comments_note));

            $report->SetXY(15,$report->GetY()+7);

            $report->SetFont('Arial','BU',14);
            $report->Cell(180,10, "ESTIMATED PROPERTY EXPENSES:");

            $report->SetXY(15,$report->GetY()+10);

            $report->SetFont('Arial','',14);
            //$report->MultiCell(180,7, $budget_data['NET EQUITY']['property_expenses']);
            $report->MultiCell(180,7, stripcslashes($property->net_equity_property_expenses_note));

            $report->SetXY(15,$report->GetY()+7);

            $report->SetFont('Arial','BU',14);
            $report->Cell(180,10, "SALES RELATED EXPENSES:");

            $report->SetXY(15,$report->GetY()+10);

            $report->SetFont('Arial','',14);
            $report->MultiCell(180,7, stripcslashes($property->net_equity_sales_related_expenses_note));


           /* $report->SetXY(15,$report->GetY()+10);

            $report->MultiCell(180,7, "Signed by:");

            $report->SetXY(15,$report->GetY()+18);

            $report->MultiCell(180,7, $property->getContactByID($property->net_equity_prepared_by)['name']);
            $report->MultiCell(180,7, $property->getContactByID($property->net_equity_prepared_by)['title']);

            $report->SetXY(15,$report->GetY()+14);
            //$report->MultiCell(180,7, $budget_data['NET EQUITY']['prepared_by_note']);
            $report->MultiCell(180,7, stripcslashes($property->net_equity_prepared_by_note));
            */

            $this->log->write('info', 'download', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'download->type' => 'net equity'
                ]
            ]);
            //return $report->Output("18-0320_USMS Pre-seizure Condition Report-Standard SFR-jd (003).pdf", "D");
            return $report->Output($property->getCode()." - ".$property->name." - Condition Report - ".date('Ymd').".pdf", "D");

        }
        catch(ModelNotFoundException $e) {
            $this->log->write('error', 'download', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'download->type' => 'net equity'
                ]
            ]);
            //return back()->with('error', 'Failed to generate "18-0320_USMS Pre-seizure Condition Report-Standard SFR-jd (003).pdf".');
            return back()->with('error', "Failed to generate '".$property->getCode()." - ".$property->name." - Condition Report - ".date('Ymd').".pdf'");
        }
    }

    public function downloadNetEquityWorksheet($property) {

        try {

            // SET FETCH MODE TO ASSOCIATIVE ARRAY, RATHER THAN OBJECT SO THAT WE CAN CREATE WORKBOOKS DIRECTLY FROM THE ARRAY
            // DB::setFetchMode(PDO::FETCH_ASSOC);
            // DB::setFetchMode(PDO::FETCH_CLASS);

            //$phase = $this->phases[$property->phase_primary]->description;
            $phase = $this->phases->where('code', $property->phase_primary)->first()->description;

            $property = $property->htmlEncodeAttributes();

            $budget_data = $property->getBudgetData();

            // CREATE A NEW WORKBOOK
            $net_equity_worksheet = Excel::create($property->getCode()." - ".$property->name." - Net Equity Worksheet - ".date('Ymd'), function($excel) use($property, $budget_data, $phase) {


                // SET THE WORKBOOK METADATA
                $excel->setTitle(config('app.pdf_title'))
                    ->setCreator(config('app.pdf_company'))
                    ->setManager('')
                    ->setCompany(config('app.pdf_company'))
                    ->setKeywords('')
                    ->setSubject('')
                    ->setDescription('Generated @ ' . date("Y-m-d H:i:s e"));

                /*
                 *  Budget
                 */
                $excel->sheet('Net Equity Worksheet', function ($sheet) use ($property, $budget_data, $phase) {

                    // STYLES
                    $currency_format = "$#,##0.00_-";
                    $accounting_format = '_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)';
                    $percent_format = "0.00%";
                    $bold = array(
                        'font' => array(
                            'bold' => true
                        )
                    );
                    $italic = array(
                        'font' => array(
                            'italic' => true
                        )
                    );
                    $centered = array(
                        'horizontal' => 'center',
                        'vertical' => 'center'
                    );
                    $right = array(
                        'horizontal' => 'right',
                        'vertical' => 'center'
                    );
                    $large = array(
                        'font' => array(
                            'size' => '24'
                        )
                    );
                    $medium = array(
                        'font' => array(
                            'size' => '18'
                        )
                    );
                    $tiny = array(
                        'font' => array(
                            'size' => '10'
                        )
                    );
                    $success = array(
                        'fill' => array(
                            'type' => 'solid',
                            'color' => array('rgb' => 'd4edda')
                        )
                    );
                    $warning = array(
                        'fill' => array(
                            'type' => 'solid',
                            'color' => array('rgb' => 'fff3cd')
                        )
                    );
                    $danger = array(
                        'fill' => array(
                            'type' => 'solid',
                            'color' => array('rgb' => 'f8d7da')
                        )
                    );

                    $label = array(

                        'fill' => array(
                            'type' => 'solid',
                            'color' => array('rgb' => 'D0D0D0')
                        ),
                        'borders' => array(
                            'allborders' => array(
                                'style' => 'thin',
                                'color' => array('rgb' => '000000')
                            )
                        )
                    );
                    $top_border = array(
                        'borders' => array(
                            'top' => array(
                                'style' => 'thin',
                                'color' => array('rgb' => '000000')
                            )
                        )
                    );
                    $right_border = array(
                        'borders' => array(
                            'right' => array(
                                'style' => 'thin',
                                'color' => array('rgb' => '000000')
                            )
                        )
                    );
                    $bottom_border = array(
                        'borders' => array(
                            'bottom' => array(
                                'style' => 'thin',
                                'color' => array('rgb' => '000000')
                            )
                        )
                    );
                    $left_border = array(
                        'borders' => array(
                            'left' => array(
                                'style' => 'thin',
                                'color' => array('rgb' => '000000')
                            )
                        )
                    );


                    // MARGINS
                    // header: 0.3"
                    // top:    0.6"
                    // right:  0.25"
                    // bottom: 0.25"
                    // left:   1.0"
                    // footer: 0.3"

                    // TOP, RIGHT, BOTTOM, LEFT
                    $sheet->setPageMargin(array(
                        1, 0.25, 0.25, 0.25
                    ));
                    $sheet->getPageMargins()->setheader(.3);

                    $sheet->setPaperSize('PAPERSIZE_LETTER');
                    $sheet->getPageSetup()->setPrintArea('A1:T45');
                    //$sheet->setShowGridlines(false);

                    $sheet->getHeaderFooter()->setDifferentOddEven(false)->setOddHeader('&L&B'."U.S. Department of Justice\n".'&-'."United States Marshals Service\n".'&R&B&16'."Real Property Net Equity Worksheet\n".'&12'."For All Property Types\n");

                    $sheet->getDefaultColumnDimension()->setWidth(5.5);


                    // ASSET INFORMATION

                    $row = 2;

                    // ROW 1
                    $sheet->mergeCells('A'.$row.':T'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($label)->applyFromArray($bold);
                    $sheet->cell('A'.$row, "PART I: ASSET INFORMATION");

                    $row++;

                    $sheet->mergeCells('A'.$row.':E'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '1. CATS Asset ID:');

                    $sheet->mergeCells('F'.$row.':J'.$row);
                    $sheet->getStyle('F'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('F'.$row, '2. USMS Pre-Seizure No.:');

                    $sheet->mergeCells('K'.$row.':O'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, '3. Court Case No.:');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, '4. District:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':E'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->code);

                    $sheet->mergeCells('F'.$row.':J'.$row);
                    $sheet->getStyle('F'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('F'.$row, $property->code_temp);

                    $sheet->mergeCells('K'.$row.':O'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, '');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, $property->group_code);

                    $row++;

                    // ROW 2
                    $sheet->mergeCells('A'.$row.':O'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '5. Full Address:');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, '6. Year Built:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':O'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->address.", ".$property->city.", ". $property->state." ".$property->zip.", ". $property->county." County");

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, $property->year_built);

                    $row++;

                    // ROW 3
                    $sheet->mergeCells('A'.$row.':J'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '7. Vested Owner(s) per Lien Report:');

                    $sheet->mergeCells('K'.$row.':T'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, '8. Owner(s) as Identified by IA/USAO:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':J'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->preseizure_name_on_title									);

                    $sheet->mergeCells('K'.$row.':T'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, $property->preseizure_owners_identified_by_ia_usao);

                    $row++;

                    // ROW 4
                    $sheet->mergeCells('A'.$row.':H'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '9. Type of Property:');

                    $sheet->mergeCells('I'.$row.':O'.$row);
                    $sheet->getStyle('I'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('I'.$row, '10. Stage:');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, '11. Forfeiture Date:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':H'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->getTypeSecondary());

                    $sheet->mergeCells('I'.$row.':O'.$row);
                    $sheet->getStyle('I'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('I'.$row, $phase);

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, $property->marketing_unmarketable_end_date);

                    $row++;

                    // ROW 5
                    $sheet->mergeCells('A'.$row.':H'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '12. APN or Owner/VIN (if SFD Manufactured Home)');

                    $sheet->mergeCells('I'.$row.':O'.$row);
                    $sheet->getStyle('I'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('I'.$row, '13. GPS Coordinates:');


                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, '14. Occupancy:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':H'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->tax_property_id, PHPExcel_Cell_DataType::TYPE_STRING);

                    $sheet->mergeCells('I'.$row.':O'.$row);
                    $sheet->getStyle('I'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('I'.$row, $property->coordinates);

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, $property->getOccupancy());

                    $row++;

                    // ROW 6
                    $sheet->mergeCells('A'.$row.':T'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '15. Additional Information:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':T'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, 'Please see condition report for property details.');

                    $row++;

                    // ROW 7
                    $sheet->mergeCells('A'.$row.':F'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, 'a. Sold at/Pending Tax Sale Date:');

                    $sheet->mergeCells('G'.$row.':L'.$row);
                    $sheet->getStyle('G'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('G'.$row, 'b. Realtor Name: (if on market)');

                    $sheet->mergeCells('M'.$row.':T'.$row);
                    $sheet->getStyle('M'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('M'.$row, 'c. Foreclosure Proceeding Bank & Date:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':F'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->preseizure_sold_at_pending_tax_sale_date);

                    $sheet->mergeCells('G'.$row.':L'.$row);
                    $sheet->getStyle('G'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('G'.$row, '');

                    $sheet->mergeCells('M'.$row.':T'.$row);
                    $sheet->getStyle('M'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('M'.$row, $property->preseizure_foreclosure_proceeding_bank." - ".$property->preseizure_foreclosure_proceeding_date);

                    $row++;
                    $row++;

                    // VALUATION INFORMATION

                    // ROW 1
                    $sheet->mergeCells('A'.$row.':T'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($label)->applyFromArray($bold);
                    $sheet->cell('A'.$row, "PART II: VALUATION INFORMATION");

                    $row++;

                    $sheet->mergeCells('A'.$row.':J'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '16. Valuation Type:');

                    $sheet->mergeCells('K'.$row.':O'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, 'Valuation Date:');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, 'Valuation Amount:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':J'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->getAppraisal1());

                    $sheet->mergeCells('K'.$row.':O'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, $property->value_current_appraised_date				);

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->getStyle('P'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('P'.$row, $property->value_current_appraised);

                    $row++;
                    $row++;

                    // MORTGAGE LIENS/JUDGEMENT EXPENSES

                    // ROW 1
                    $sheet->mergeCells('A'.$row.':T'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($label)->applyFromArray($bold);
                    $sheet->cell('A'.$row, "PART III: MORTGAGE LIENS/JUDGEMENT EXPENSES");

                    $row++;

                    $sheet->mergeCells('A'.$row.':J'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '17. First Mortgage Lienholder:');

                    $sheet->mergeCells('K'.$row.':O'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('K'.$row, 'Recorded Date:');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, 'Lien Amount:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':J'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);

                    $sheet->mergeCells('K'.$row.':O'.$row);
                    $sheet->getStyle('K'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->getStyle('P'.$row)->getNumberFormat()->setFormatCode($accounting_format);


                    $liens = json_decode($property->financial_liens, true);
                    if($liens) {

                        $liens = array_filter($liens, function ($lien) {
                            return $lien['Type'] == 'Mortgage';
                        });

                        $liens = array_filter($liens, function ($lien) {
                            return $lien['Released'] == 'No';
                        });

                        $lien = array_shift($liens);
                        $sheet->cell('A'.$row, $lien['Lienholder Name']);
                        $sheet->cell('K'.$row, $lien['Filing Date']);
                        $sheet->cell('P'.$row, $lien['Amount']);
                    }
                    else
                        $lien['Amount'] = 0;

                    $row++;

                    $sheet->mergeCells('A'.$row.':G'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '18. Base Year Real Estate Taxes:');

                    $sheet->mergeCells('H'.$row.':M'.$row);
                    $sheet->getStyle('H'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('H'.$row, 'Taxes Paid:');

                    $sheet->mergeCells('N'.$row.':T'.$row);
                    $sheet->getStyle('N'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('N'.$row, "Prior Years' Delinquent Taxes");

                    $row++;

                    $sheet->mergeCells('A'.$row.':G'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->getStyle('A'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('A'.$row, $property->tax_current_amount_due);

                    $sheet->mergeCells('H'.$row.':M'.$row);
                    $sheet->getStyle('H'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->getStyle('H'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('H'.$row, $property->tax_previous_amount_due);

                    $sheet->mergeCells('N'.$row.':T'.$row);
                    $sheet->getStyle('N'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->getStyle('N'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('N'.$row, $property->tax_past_due_amount);

                    $row++;

                    $sheet->mergeCells('A'.$row.':O'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, '19. Supplemental Liens/ Judgement Expenses: Please see attached report for full lien detail(s).');

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($bold)->applyFromArray($left_border)->applyFromArray($top_border)->applyFromArray($right_border);
                    $sheet->cell('P'.$row, 'Supplemental Lien Amount:');

                    $row++;

                    $sheet->mergeCells('A'.$row.':O'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->cell('A'.$row, $property->preseizure_new_title_notes);

                    $sheet->mergeCells('P'.$row.':T'.$row);
                    $sheet->getStyle('P'.$row)->applyFromArray($left_border)->applyFromArray($bottom_border)->applyFromArray($right_border);
                    $sheet->getStyle('P'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('P'.$row, $property->financial_total_liens - $lien['Amount']);

                    $row++;
                    $row++;


                    // NET EQUITY SUMMARY

                    // ROW 1
                    $sheet->mergeCells('A'.$row.':T'.$row);
                    $sheet->getStyle('A'.$row)->applyFromArray($label)->applyFromArray($bold);
                    $sheet->cell('A'.$row, "PART IV: NET EQUITY SUMMARY");

                    $row++;
                    $row++;

                    $sheet->mergeCells('D'.$row.':J'.$row);
                    $sheet->getStyle('D'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->cell('D'.$row, 'Valuation Amount');

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, $property->value_current_appraised);

                    $row++;

                    $sheet->mergeCells('D'.$row.':J'.$row);
                    $sheet->getStyle('D'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->cell('D'.$row, 'Income');

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, $budget_data['SUMMARY']['total_revenue']['recurring_non_recurring_items']);

                    $row++;

                    $sheet->mergeCells('D'.$row.':J'.$row);
                    $sheet->getStyle('D'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->cell('D'.$row, 'Total Liens');

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, '-'.$property->financial_total_liens);

                    $row++;

                    $sheet->mergeCells('D'.$row.':J'.$row);
                    $sheet->getStyle('D'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->cell('D'.$row, 'Prior Expenditures');

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, $budget_data['SUMMARY']['total_projected_income_loss']['prior_expenditures']);

                    $row++;

                    $sheet->mergeCells('B'.$row.':J'.$row);
                    $sheet->getStyle('B'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->cell('B'.$row, 'Management & 12-month Maintenance Expenses');

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, '-'.$budget_data['SUMMARY']['total_operating_expenses']['recurring_non_recurring_items']);

                    $row++;

                    $sheet->mergeCells('D'.$row.':J'.$row);
                    $sheet->getStyle('D'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->cell('D'.$row, 'Sale - Related Expenses');

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, '-'.$budget_data['SUMMARY']['total_resolution_expenses']['recurring_non_recurring_items']);

                    $row++;

                    $sheet->mergeCells('D'.$row.':J'.$row);
                    $sheet->getStyle('D'.$row)->applyFromArray($bold)->getAlignment()->applyFromArray($right);
                    $sheet->cell('D'.$row, 'Total');

                    $total = $property->value_current_appraised + $budget_data['SUMMARY']['total_revenue']['recurring_non_recurring_items'] - $property->financial_total_liens + $budget_data['SUMMARY']['total_projected_income_loss']['prior_expenditures'] - $budget_data['SUMMARY']['total_operating_expenses']['recurring_non_recurring_items'] - $budget_data['SUMMARY']['total_resolution_expenses']['recurring_non_recurring_items'];

                    $sheet->mergeCells('K'.$row.':P'.$row);
                    $sheet->getStyle('K'.$row)->getAlignment()->applyFromArray($right);
                    $sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode($accounting_format);
                    $sheet->cell('K'.$row, $total);

                    $row++;
                    $row++;

                    $sheet->mergeCells('E'.$row.':P'.$row);
                    $sheet->getStyle('E'.$row)->applyFromArray($centered)->applyFromArray($bold)->applyFromArray($large)->getAlignment()->applyFromArray($centered);
                    $sheet->cell('E'.$row, 'TOTAL NET EQUITY');

                    $row++;

                    if ($budget_data['NET EQUITY']['class'] == 'threshold alert alert-success')
                        $alert = $success;
                    elseif ($budget_data['NET EQUITY']['class'] == 'threshold alert alert-warning')
                        $alert = $warning;
                    else
                        $alert = $danger;

                    $sheet->mergeCells('E'.$row.':P'.($row+1));
                    $sheet->getStyle('E'.$row)->applyFromArray($bold)->applyFromArray($large)->applyFromArray($centered)->applyFromArray($alert)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode($currency_format);
                    $sheet->cell('E'.$row, $total);

                    $row++;
                    $row++;

                    $sheet->mergeCells('E'.$row.':P'.$row);
                    $sheet->getStyle('E'.$row)->applyFromArray($bold)->applyFromArray($medium)->applyFromArray($alert)->getAlignment()->applyFromArray($centered);
                    $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode($percent_format);
                    $sheet->cell('E'.$row, $budget_data['NET EQUITY']['%'] / 100);

                    $row++;
                    $row++;

                    $sheet->mergeCells('A'.$row.':T'.($row));
                    $sheet->getRowDimension($row)->setRowHeight(48);
                    $sheet->getStyle('A'.$row)->getAlignment()->applyFromArray($centered)->applyFromArray($tiny)->setWrapText(true);
                    $sheet->cell('A'.$row, "* Management & 12-Month Maintenance Expenses include items such as association dues, grounds work, current & back taxes, utilities, cleaning, pre-seizure & custody work, as well as maintenance and repairs.");



                });

            });
            //$path = $property->getPath()."/Six Part File/2-Budget & Cases";
            //$budget_excel->store('xls', $path);
            //$budget_excel->download('xlsx');



            // LOG THE FILE DOWNLOAD
            $this->log->write('info', 'download', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'download->type' => 'net equity worksheet'
                ]
            ]);

            if(Route::currentRouteName() == 'downloadNetEquityWorksheet')
                $net_equity_worksheet->download('xlsx');
            else
                return $net_equity_worksheet;
        }
        catch(ModelNotFoundException $e) {
            $this->log->write('error', 'download', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'download->type' => 'net equity worksheet'
                ]
            ]);
            return redirect()->route('properties')->with('error', 'Failed to download net equity worksheet for property '.$property->id.'.');
        }
    }

    public function downloadBudgetReport($property) {
        return $property->generateBudget();
    }

    // THIS ALLOWS DISTRICTS TO SEE INVOICES ASSOCIATED WITH THEIR ASSIGNED PROPERTIES, BUT NOT ALL THE OTHER LINE ITEMS YOU'D SEE ON A FULL INVOICE, TO WHICH THEY WOULDN'T HAVE ACCESS
    public function downloadInvoiceTransmittal($property, $invoice, $signature=false) {

        try {

            $property = $property->htmlEncodeAttributes();


            // NEED TO MANUALLY INSTANTIATE FPDF EACH TIME [nkh]
            //     USING THE FACADE CAUSES IT TO TRY AND WRITE EACH PDF TO THE SAME (CLOSED) FILE EACH TIME WHEN USED IN BATCH MODE
            //$pdf = new FPDF();
            $pdf = new \Codedge\Fpdf\Fpdf\Fpdf;

            // METADATA
            $pdf->SetAuthor(config('app.pdf_author'));
            $pdf->SetTitle('Invoice Transmittal');


            // NEW PAGE
            $pdf->AddPage('P');
            $pdf->SetDisplayMode('real','default');
            $pdf->SetMargins(10, 10, 10);


            // HEADER
            $pdf->SetFont('Courier','B',30);
            $pdf->Cell(190,15,' Invoice Transmittal',0,0,'C',0);
            $pdf->SetFont('', '', 10);
            $pdf->SetXY(10,$pdf->GetY()+9);
            $pdf->Cell(190,15,'Contract # ' . Contract::default()->task_order_number ." | Invoice # ".$invoice->number." | ".$invoice->date,0,0,'C',0);

            // BREAK
            $pdf->SetLineWidth(.05);
            $pdf->Line(10,32,200,32);
            $pdf->SetXY(10,$pdf->GetY()+16);


            // ASSET
            $pdf->Cell(190,10,'Asset: '.$property->getCode());
            $pdf->SetXY(20,$pdf->GetY()+10);

            $pdf->Cell(190,10, $property->address);
            $pdf->SetXY(20,$pdf->GetY()+5);
            $pdf->Cell(190,10, $property->city.", ".$property->state." ".$property->zip);
            $pdf->SetXY(20,$pdf->GetY()+10);
            $pdf->Cell(190,10, $property->getStatusSecondary());
            $pdf->SetXY(10,$pdf->GetY()+15);


            // COLLIERS
            $pdf->Cell(190,10,'Colliers International');
            $pdf->SetXY(10,$pdf->GetY()+5);

            $pdf->Cell(190,10,'C/O ORE Financial Services, LLC');
            $pdf->SetXY(20,$pdf->GetY()+10);
            $pdf->Cell(190,10, 'P.O. Box 671346');
            $pdf->SetXY(20,$pdf->GetY()+5);
            $pdf->Cell(190,10, 'Houston, TX 77267');
            $pdf->SetXY(10,$pdf->GetY()+10);
            //$pdf->Cell(190,10, 'Contract # ' . Contract::default()->task_order_number);
            //$pdf->SetXY(10,$pdf->GetY()+15);


            // INVOICE
            //$pdf->Cell(190,10,'Invoice #: '.str_pad($invoice->number,36).'Date: '.$invoice->date);
            //$pdf->SetXY(10,$pdf->GetY()+15);


            // LINE ITEMS
            // Pre-generate all line items so that we can A) have graceful linebreaks and B) keep the totals at the top
            $rows = [];

            $line_items = $invoice->line_items->where('property_id', $property->id); //->toArray();
            $total = $line_items->sum('amount');

            foreach($line_items as $line_item) {

                $details = $line_item->getFormattedClin();
                $code = $details['code'];   //"Pre‐Seizure Analysis" "Pre-Seizure Analysis"

                // FPDF DOESN'T SUPPORT UNICODE
                // TODO: come up with a more elegant way to fix unicode characters all at once, rather than on a case by case bassis
                $description = str_replace("‐","-",$details['description']);

                $rows[] = str_pad($code." | ".$line_item->order_line_item_id." ",(87-strlen('$'.number_format($line_item->amount,2))),'.').' $'.number_format($line_item->amount,2);
                $rows[] = $description;

                foreach($line_item->credits as $credit) {
                    $total = $total - $credit->amount;
                    $rows[] = str_pad('         CREDIT: ' . $credit->credit_date . " | ".$credit->reference, (88 - strlen('($' . number_format($credit->amount, 2) . ')')), '.') . ' ($' . number_format($credit->amount, 2) . ')';
                }
                $rows[] = 'line break';
            }

            $pdf->Cell(190,10,str_pad('TOTAL: $'.number_format($total,2),88,' ',STR_PAD_LEFT));
            $pdf->SetXY(10,$pdf->GetY()+10);

            $pdf->SetFont('', '', 10);

            foreach($rows as $key => $row) {
                if ($pdf->GetY() >= 250 && $signature) {
                    $pdf->SetFont('', 'I', 9);
                    $pdf->SetXY(10, $pdf->GetY() + 10);
                    $pdf->Cell(190, 10, 'continued on next page', 0, 0, 'C');
                    $pdf->SetXY(10, $pdf->GetY() + 10);
                    break;
                }

                if ($row == 'line break') {
                    $pdf->SetXY(10, $pdf->GetY() + 5);
                }
                else {

                    $pdf->Cell(190, 10, $row);
                    $pdf->SetXY(10, $pdf->GetY() + 5);

                    unset($rows[$key]);
                }
            }

            // SIGNATURE AND DATE
            if($signature) {
                $pdf->SetFont('', 'I', 12);
                $pdf->Text(10, 285, 'Approved By: ________________________________________     Date: __________');
                $pdf->SetFont('', '', 10);
            }

            // SUBSEQUENT PAGES
            if($rows) {
                foreach ($rows as $key => $row) {

                    if ($row == 'line break') {
                        $pdf->SetXY(10, $pdf->GetY() + 5);
                    }
                    else {

                        $pdf->Cell(190, 10, $row);
                        $pdf->SetXY(10, $pdf->GetY() + 5);

                        unset($rows[$key]);
                    }
                }
            }

            $this->log->write('info','download', [
                'id' => $property->id,
                'metadata' => [
                    'invoice_number' => $invoice->number
                ]
            ]);

            /*
             * I: send the file inline to the browser. The plug-in is used if available. The name given by name is used when one selects the "Save as" option on the link generating the PDF.
             * D: send to the browser and force a file download with the name given by name.
             * F: save to a local file with the name given by name (may include a path).
             * S: return the document as a string. name is ignored.
             */
            $filename = "Invoice_Transmittal_".date('Y-m-d')."_".$property->getCode()."_".$invoice->number.".pdf";
            if(Route::currentRouteName() == 'downloadInvoiceTransmittal') {

                $filepath = '/tmp/'.$filename;
                $pdf->Output($filepath, "F");

                // THIS SHOULD FORCE CHROME TO SHOW THE FILE INLINE VIA THE BROWSER, RATHER THAN A DOWNLOAD PROMPT
                return response()->file($filepath);
                //return $pdf->Output($filename, "D");
            }
            else {
                $filepath = '/tmp/'.$filename;
                $pdf->Output($filepath, "F");
                return $filepath;
            }

        }
        catch(ModelNotFoundException $e) {
            $this->log->write('error','download', [
                'id' => $property->id,
                'metadata' => [
                    'invoice_number' => $invoice->number
                ]
            ]);
            return redirect()->back()->with('error', 'Failed to generate PDF for invoice '.$invoice->number.'.');
        }
    }

    public function downloadAccountingTabZip($property)
    {
        PropertyAccountingTabExport::dispatch(Auth::user(), $property);

        return redirect()->back()->with('success', 'Accounting tab file export has been queued. You will receive an email with download instructions once the export is complete.');


    }

    public function downloadAccountingTabExcel($property)
    {

        // CREATE A NEW WORKBOOK
        $title = date("YmdHis").".".$property->getCode().".Accounting_Tab.zip";
        $report = Excel::create($title, function ($excel) use ($property) {

            // SET THE WORKBOOK METADATA
            $excel->setTitle(config('app.pdf_title'))
                  ->setCreator(config('app.pdf_company'))
                  ->setManager('')
                  ->setCompany(config('app.pdf_company'))
                  ->setKeywords('')
                  ->setSubject('')
                   ->setDescription('Generated @ '.date("Y-m-d H:i:s e"));

            $excel->sheet('Accounting Tab', function ($sheet) use ($property) {

                $now = Carbon::now();

                $rows = $property->getAccountingTabRows();

                $counter = 1;
                $total = 0;

                $sheet->row($counter, [
                    "Date",
                    "Type",
                    "Number",
                    "Status",
                    "Amount"
                ]);
                $sheet->row($counter, function ($row) {
                    $row->setFontWeight('bold');
                });

                foreach ($rows as $row) {
                    $counter++;
                    $total += $row['Amount'];

                    $sheet->row($counter, [
                        $row["Date"],
                        $row["Type"],
                        $row["Number"],
                        $row["Status"],
                        $row["Amount"]
                    ]);
                }
                $counter += 2;
                $sheet->row($counter, [
                    null,
                    null,
                    null,
                    "TOTAL",
                    $total
                ]);
                $sheet->getStyle('D'.$counter.':D'.$counter)->applyFromArray([
                    'font' => [
                        'bold' => true
                    ]
                ]);
            });
        });

        $this->log->write('info', 'download', [
            'id' => $property->id,
            'metadata' => [
                'property->code' => $property->code,
                'property->code_temp' => $property->code_temp,
                'download->type' => 'accounting tab excel export'
            ]
        ]);

        if (Route::currentRouteName() == 'downloadAccountingTabExcel') {
            $report->download('xlsx');
        } else {
            $report->store('xls', "/tmp");
            return "/tmp/".$title.".xls";
        }

    }

    public function getBudgetSummary($property) {
        return $property->getBudgetSummary();
    }

    public function listClins($property) {
        return $property->tasks()->whereNotIn('order_line_items.status', [5,6,9,10,12])->pluck('code')->toArray();
    }

    public function getAccountingMatrix($property) {
        // SKIPPING THE REQUESTING VENDOR BILL PREVENTS DOUBLE COUNTING IN THE CLIN XREF
        $isLoadingHistorical = InvoiceRepository::isLoadingHistorical();
        return $property->getAccountingMatrix(
            request()->type,
            request()->requesting_vendor_bill,
            $isLoadingHistorical,
            request()->start_date,
            request()->end_date
        );
    }

    public function getRelatedVendorBills($property)
    {
        return InvoiceLineItem::getVendorBills($property);
    }

    public function getFeeSchedule($property) {
        $request = request();
        $assignment_date = $request->input("assignment_date");
        $type_primary = $request->input("type_primary");
        $type_secondary = $request->input("type_secondary");
        $value_current_appraised = $request->input("value_current_appraised");
        $property_management_occupancy_status = $request->input("property_management_occupancy_status");

        return $property->getFeeSchedule($assignment_date, $type_primary, $type_secondary, $value_current_appraised, $property_management_occupancy_status);
    }


    public function downloadMarketingPollerReport($property) {

        try {

            $polling_history = json_decode($property->marketing_site_polling_history, true);

            // CREATE A NEW WORKBOOK
            $title = $property->getCode()." - ".$property->name." - Poller Report - ".date('Ymd');
            $report = Excel::create($title, function ($excel) use ($polling_history, $property) {

                // SET THE WORKBOOK METADATA
                $excel->setTitle(config('app.pdf_title'))
                    ->setCreator(config('app.pdf_company'))
                    ->setManager('')
                    ->setCompany(config('app.pdf_company'))
                    ->setKeywords('')
                    ->setSubject('')
                    ->setDescription('Generated @ ' . date("Y-m-d H:i:s e"));

                /*
                 *  ACTIVE ASSETS
                 */
                $excel->sheet('Poller Report', function ($sheet) use ($polling_history, $property) {
                    $counter = 1;
                    $sheet->row($counter, ["URL", config('app.marketing_url').'/asset.php?a='.(config('app.client') == 'USMS' ? $property->id : $property->code)]);

                    $counter++;
                    $sheet->row($counter, ["Marketing Start Date", $property->marketing_start_date]);

                    $sheet->getStyle('A1:A3')->applyFromArray( array('font' => array('bold' => true)));

                    $counter++;
                    $counter++;
                    $sheet->row($counter, ["Date Polled", "Days on Marketing Site", "Note"]);
                    $sheet->row($counter, function ($row) {
                        $row->setFontWeight('bold');
                    });

                    foreach($polling_history as $history) {
                        $counter++;
                        $sheet->row($counter, [$history['Date'], $history['Days'], $history['Note']]);
                    }


                    //$sheet->fromArray($polling_history);
                });

            });

            // LOG WHAT WAS DONE
            try {
                $this->log->write('info', 'download', [
                    'id' => $property->id,
                    'metadata' => [
                        'property->code' => $property->code,
                        'property->code_temp' => $property->code_temp,
                        'download->type' => 'marketing poller report'
                    ]
                ]);
            } catch (Exception $ex) {

            } catch (Throwable $ex) {

            }

            if (Route::currentRouteName() == 'downloadMarketingPollerReport')
                if (empty($polling_history))
                    return back()->with('warning', 'This asset has no polling history.');
                else
                    $report->download('xlsx');
            else {
                $report->store('xls', "/tmp");
                if (empty($polling_history))
                    return false;
                else
                    return "/tmp/" . $title . ".xls";
            }
        }
        catch(ModelNotFoundException $e) {
            $this->log->write('error', 'download', [
                'id' => $property->id,
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'download->type' => 'marketing poller report'
                ]
            ]);
            return back()->with('error', 'Failed to generate marketing poller report.');
        }
    }

    public function createShare(Request $request, $property) {

        try {
            $expiration = date("Y-m-d", strtotime( '+1 months' ) );
            Artisan::call('owncloud:create', ['name' => $request->name,
                                          'path' => $request->path."/".$request->name,
                                          'pass' => 'null',
                                          'date' => $expiration
            ]);
            $url = str_replace("\n", "", Artisan::output());

            $this->log->write('info', 'share', [
                'id' => $property->id,
                'module' => 'files',
                'metadata' => [
                    'auth->id' => Auth::user()->id, 'auth->username' => Auth::user()->username,
                    'property->id' => $property->id,
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'action' => 'create',
                    'path' => $request->path,
                    'share' => $url
                ]
            ]);

            return json_encode(['url' => $url, 'expiration' => $expiration, 'stime' => date("Y-m-d")]);

        }
        catch(ModelNotFoundException $e) {
            $this->log->write('error', 'share', [
                'id' => $property->id,
                'module' => 'files',
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'action' => 'create',
                    'path' => $request->path,
                    'share' => $url
                ]
            ]);
            //return back()->with('error', 'Failed to generate "'.$property_code.' - Transfer Custody Report.pdf".');
        }
    }

    public function deleteShare(Request $request, $property) {

        try {

            Artisan::call('owncloud:delete', ['id' => $request->id]);
            $this->log->write('info', 'unshare', [
                'id' => $property->id,
                'module' => 'files',
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'action' => 'delete',
                    'ID' => $request->id
                ]
            ]);
            $result = Artisan::output();
            return $result;

        }
        catch(ModelNotFoundException $e) {
            $this->log->write('error', 'unshare', [
                'id' => $property->id,
                'module' => 'files',
                'metadata' => [
                    'property->code' => $property->code,
                    'property->code_temp' => $property->code_temp,
                    'action' => 'delete',
                    'ID' => $request->id
                ]
            ]);
            //return back()->with('error', 'Failed to generate "'.$property_code.' - Transfer Custody Report.pdf".');
        }
    }

    /**
     * Get JSON / Log data from a specific field
     * @param Property $property
     * @param $field - which table field we're getting
     * @return Factory|View
     */
    public function getLogData(Property $property, $field)
    {
        $fieldKey = old($field) ?: $field;
        $orderBy = request()->has('order') ? request()->order : 'Date';
        $rows = $property->getLogData($fieldKey, $orderBy);
        return view('properties.tables.'.$field, ['rows' => $rows]);
    }

    /**
     * Update a JSON / Log data field
     * @param Property $property
     * @param $field - which table field we're editing
     * @param Request $request
     * @return false|string
     */
    public function postLogData(Property $property, $field, Request $request)
    {
        // TODO: validate selected field against list of valid fields
        $property->{$field} = json_encode($request->input('data'));
        // TODO: update special cases like this:
//        $property->financial_total_liens = $request->input('financial_total_liens');

        if($field == 'purchasers_log') {

        }

        $orderBy = request()->has('order') ? request()->order : 'Date';
        $rows = $property->getLogData($field, $orderBy);
        $property->timestamps = false;
        return json_encode([
            'status' => $property->save(),
            'rows' => $rows,
        ]);
    }

    private function syncPropertyInspections($property, $json)
    {
        return PropertyInspection::import($property, $json);
    }
}
