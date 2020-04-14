<?php
use Illuminate\Support\Facades\Request;

/* TRY/CATCH BLOCK FOR USE IN LARAVEL

try {
    App\Models\justDoIt::find(1);
} catch (\Exception $ex) {
    dd('Exception block', $ex);
} catch (\Throwable $ex) {
    dd('Throwable block', $ex);
}

 */


/*
 *
php artisan route:cache
php artisan view:clear
php artisan config:cache

 */

// ROUTE MODEL BINDING
Route::model('user', 'App\User');
Route::model('role', 'App\Role');
Route::model('permission', 'App\Permission');
Route::model('group', 'App\Group');
Route::model('property', 'App\Property');
Route::model('vendor', 'App\Vendor');
Route::model('vendorBill', 'App\VendorBill');
Route::model('invoice', 'App\Invoice');
Route::model('invoiceLineItem', 'App\InvoiceLineItem');
Route::model('invoicePayment', 'App\InvoicePayment');
Route::model('invoiceCredit', 'App\InvoiceCredit');
Route::model('contact', 'App\Contact');
Route::model('contactType', 'App\ContactType');
Route::model('mapping', 'App\Mapping');
Route::model('contract', 'App\Contract');
Route::model('period', 'App\ContractPeriod');
Route::model('contract_line_item', 'App\ContractLineItem');
Route::model('order', 'App\Order');
Route::model('orderLineItem', 'App\OrderLineItem');
Route::model('orderLineItemObligation', 'App\OrderLineItemObligation');


// DEFAULT AUTH ROUTES
// Auth::routes();

// EXPLICITLY SET THE AUTH ROUTES SO THAT WE CAN USE THE ROOT PAGE AS LOGIN
Route::get('/', 'Auth\LoginController@showLoginForm')->name('login');
Route::post('/', 'Auth\LoginController@login');
Route::get('/logout', 'Auth\LoginController@logout'); # HACK BACK IN THE GET LOGOUT
Route::post('/logout', 'Auth\LoginController@logout')->name('logout');



/*
 * AUTHENTICATED ROUTES
 *
 * Controlled by the default auth middleware and the expirePwd middleware.
 */

// TOGGLE THESE LINES TO ALLOW ONLY SUPERUSER ACCESS TO THE SITE
//Route::group(['prefix' => '/', 'middleware' => ['auth','expirepwd','role:super-user']], function() {
// MAINTENANCE MODE
Route::group(['prefix' => '/', 'middleware' => ['auth','expirepwd','lockdown']], function () {
 // STANDARD MODE

    // KEEP ALIVE
    Route::get('/keep-alive', 'ContactsController@keepAlive')->name('keep-alive');

    // TEST BED FOR NEW FEATURES
    Route::group(['prefix' => '/test', 'middleware' => ['role:super-user']], function () {
        Route::get('/charts', [
            'uses' => 'TestController@charts',
            'middleware' => ['role:super-user']
        ])->name('test-charts');
        Route::get('/pdf', ['uses' => 'TestController@pdf', 'middleware' => ['role:super-user']])->name('test-pdf');
        Route::get('/contracts', [
            'uses' => 'TestController@contracts',
            'middleware' => ['role:super-user']
        ])->name('contracts');
        Route::get('/tasks', [
            'uses' => 'TestController@listTasks',
            'middleware' => ['role:super-user']
        ])->name('test-tasks');
        Route::get('/email', [
            'uses' => 'TestController@email',
            'middleware' => ['role:super-user']
        ])->name('test-email');
        Route::get('/webpack', [
            'uses' => 'TestController@webpack',
            'middleware' => ['role:super-user']
        ])->name('test-webpack');
    });

    Route::get('/errors/{file}', function ($filename) {
        return response()->download("/tmp/errors/".$filename)->deleteFileAfterSend(true);
    });

    Route::get('/zendesk/sso', 'ContactsController@sso')->name('sso');

    // ACCOUNT - OPEN TO ALL AUTHENTICATED USERS
    Route::get('/account', 'ContactsController@getAccount')->name('account');
    Route::post('/account', 'ContactsController@postAccount')->name('postAccount');

    // LOCKDOWN MODE
    Route::group(['middleware' => ['role:super-user']], function () {
        Route::post('/lockdown', 'SettingsController@lockdown')->name('postLockdown');
    });

    // TASKS - ASSIGNED AS A PERMISSION IN ROLES MODULE; PULLS ORDERS, ORDER LINE ITEMS, AND VENDOR BILLS
    Route::group(['prefix' => '/tasks', 'middleware' => []], function () {
        Route::get('/', [
            'uses' => 'ContactsController@listTasks',
            'middleware' => ['permission:tasks_view_task_list']
        ])->name('tasks');

        // QUERY DATATABLE
        Route::post('/datatable', 'ContactsController@tasksDatatable');
    });


    /* CONTACTS MODULE
     *
     * User.php, Contact.php, ContactType.php, ContactsController.php
     *
     * 'contacts' table stores standard contact information such as name, email address, etc. Additionally,
     *     a contact can be either an individual or an organization, using the 'type' column (1=individual,
     *     2=organization). Individuals are assigned to organizations using the the 'organization_id' column.
     *
     * 'contact_type' table determines the rows that populate on the properties->contacts tab. Contacts may
     *     be assigned to multiple types using the contact->types tab.
     *
     * 'users' table contains login credentials. All users have a corresponding entry in the 'contacts' table,
     *     determined by the 'contact_id' column.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - TODO: add hard deletes
     */
    Route::group(['prefix' => '/contacts', 'middleware' => ['permission:contacts_view']], function () {

        // LIST
        Route::get('/', 'ContactsController@listContacts')->name('contacts');

        // QUERY DATATABLE
        Route::post('/datatable', 'ContactsController@datatable');

        // SEARCH
        Route::get('/search/{search}', 'ContactsController@searchContacts')->name('searchContacts');

        // VIEW
        Route::get('/view/{contact}', 'ContactsController@getContact')->name('viewContact');

        // EDIT
        Route::get('/edit/{contact}', [
            'uses' => 'ContactsController@getContact',
            'middleware' => ['permission:contacts_edit']
        ])->name('editContact');
        Route::post('/edit/{contact}', [
            'uses' => 'ContactsController@postContact',
            'middleware' => ['permission:contacts_edit']
        ])->name('postEditContact');

        // ADD
        Route::get('/add', [
            'uses' => 'ContactsController@addContact',
            'middleware' => ['permission:contacts_add']
        ])->name('addContact');
        Route::post('/add', [
            'uses' => 'ContactsController@postContact',
            'middleware' => ['permission:contacts_add']
        ])->name('postAddContact');

        // DISABLE / RESTORE
        Route::post('/disable/{contact}', [
            'uses' => 'ContactsController@disableContact',
            'middleware' => ['permission:contacts_delete']
        ])->name('postDeleteContact');
        Route::post('/restore/{id}', [
            'uses' => 'ContactsController@restoreContact',
            'middleware' => ['role:super-user']
        ]);

        // CONTACT TYPES
        Route::group(['prefix' => '/types', 'middleware' => ['permission:contact_types_view']], function () {

            // LIST
            Route::get('/', 'ContactsController@listTypes')->name('types');

            /// VIEW
            Route::get('/view/{contactType}', 'ContactsController@getType')->name('viewType');

            // EDIT
            Route::get('/edit/{contactType}', [
                'uses' => 'ContactsController@getType',
                'middleware' => ['permission:contact_types_edit']
            ])->name('editType');
            Route::post('/edit/{contactType}', [
                'uses' => 'ContactsController@postType',
                'middleware' => ['permission:contact_types_edit']
            ])->name('postEditType');

            //ADD
            Route::get('/add', [
                'uses' => 'ContactsController@addType',
                'middleware' => ['permission:contact_types_add']
            ])->name('addType');
            Route::post('/add', [
                'uses' => 'ContactsController@postType',
                'middleware' => ['permission:contact_types_add']
            ])->name('postAddType');

            // DISABLE / RESTORE
            Route::post('/disable/{contactType}', [
                'uses' => 'ContactsController@disableType',
                'middleware' => ['permission:contact_types_delete']
            ]);
            Route::post('/restore/{id}', [
                'uses' => 'ContactsController@restoreType',
                'middleware' => ['role:super-user']
            ]);
        });
    });


    /* PROPERTIES MODULE
     *
     * Property.php, PropertiesController.php
     *
     * This is the primary work horse of the system. The majority of permissions for this route group are controlled
     *     via the property_permissions middleware. Property permissions are generated on a per user basis via
     *     User->propertyPermissions().
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view, via
     *            the roles module
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - hard delete a record; super-user only
     */
    Route::group(['prefix' => '/properties', 'middleware' => ['permission:properties_view']], function () {

        // LIST
        Route::get('/', 'PropertiesController@listProperties')->name('properties');

        // MAP
        Route::get('/map', [
            'uses' => 'PropertiesController@mapProperties',
            'middleware' => ['permission:properties_map']
        ])->name('mapProperties');

        // QUERY DATATABLE
        Route::post('/datatable', 'PropertiesController@datatable');

        // SEARCH
        Route::get('/search/{search}', 'PropertiesController@searchProperties')->name('searchProperties');

        // VIEW
        Route::group(['prefix' => '/view/{property}', 'middleware' => ['property_permissions:view']], function () {

            Route::get('/', 'PropertiesController@getProperty')->name('viewProperty');

            // DOWNLOADS - NOTE THAT DOWNLOADS ARE TIED TO THE VIEW PERMISSION, EVEN WHEN ACCESSED FROM OTHER MODES

            // PULL IMAGES FROM THE PROPERTIES OWNCLOUD FILE REPOSITORY, WHICH IS OUTSIDE THE WEBROOT
            Route::get('/image', ['uses' => 'PropertiesController@image', 'middleware' => []]);
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/download', ['uses' => 'PropertiesController@download', 'middleware' => []]);
            // OWNCLOUD FILE VIWEWER
            Route::get('/view', ['uses' => 'PropertiesController@view', 'middleware' => []]);

            Route::get('/download/brochure', ['uses' => 'PropertiesController@downloadBrochure', 'middleware' => []]);
            Route::get('/download/condition_report', [
                'uses' => 'PropertiesController@downloadConditionReport',
                'middleware' => []
            ]);

            Route::get('/download/net_equity_worksheet', [
                'uses' => 'PropertiesController@downloadNetEquityWorksheet',
                'middleware' => []
            ])->name('downloadNetEquityWorksheet');

            Route::get('/download/transfer_custody_report', [
                'uses' => 'PropertiesController@downloadTransferCustodyReport',
                'middleware' => ['permission:properties_download_transfer_custody_report']
            ])->name('downloadTransferCustodyReport');

            Route::get('/download/marketing_poller_report', [
                'uses' => 'PropertiesController@downloadMarketingPollerReport',
                'middleware' => ['role:super-user']
            ])->name('downloadMarketingPollerReport');

            Route::get('/download/budget_report', [
                'uses' => 'PropertiesController@downloadBudgetReport',
                'middleware' => ['permission:properties_download_budget_report']
            ])->name('downloadBudgetReport');

            Route::get('/download/invoice/{invoice}', [
                'uses' => 'PropertiesController@downloadInvoiceTransmittal',
                'middleware' => []
            ])->name('downloadInvoiceTransmittal');

            Route::get('/download/accountingTabZip', [
                'uses' => 'PropertiesController@downloadAccountingTabZip',
                'middleware' => []
            ])->name('downloadAccountingTabZip');

            Route::get('/download/accountingTabExcel', [
                'uses' => 'PropertiesController@downloadAccountingTabExcel',
                'middleware' => []
            ])->name('downloadAccountingTabExcel');

            // PULL BUDGET DATA FOR A GIVEN PROPERTY; IN USE ON orders/add.blade.php
            Route::post('/budget/summary', [
                'uses' => 'PropertiesController@getBudgetSummary',
                'middleware' => []
            ])->name('getBudgetSummary');

            /* PULL ALLOWABLE AND AUTO-POPULATED CLINS ASSIGNED TO A GIVEN PROPERTY
             *
             * IN USE ON
             *     orders/add.blade.php
             *     js/orders-js.blade.php
             *     js/orderLineItems-js.blade.php
             */
            Route::post('/list/clins', [
                'uses' => 'PropertiesController@listClins',
                'middleware' => []
            ])->name('listClins');

            /* GENERATE A MATRIX OF CLINS ASSIGNED TO A GIVEN PROPERTY, ALONG WITH COST TOTAL, SPENT, AND AVAILABLE
             * FOR EACH CLIN
             *
             * IN USE ON
             *     orders/add.blade.php
             */
            Route::post('/getAccountingMatrix', [
                'uses' => 'PropertiesController@getAccountingMatrix',
                'middleware' => []
            ])->name('getAccountingMatrix');
            Route::get('/vendor_bills', [
                'uses' => 'PropertiesController@getRelatedVendorBills',
                'middleware' => []
            ])->name('getRelatedVendorBills');

            // GENERATE A MATRIX OF COSTS FOR A GIVEN PROPERTY
            Route::post('fee_schedule', [
                'uses' => 'PropertiesController@getFeeSchedule',
                'middleware' => []
            ])->name('getFeeSchedule');

            Route::get('/shares', [
                'uses' => 'PropertiesController@shares',
                'middleware' => []
            ])->name('viewPropertyShares');
        });

        // EDIT
        Route::group(['prefix' => '/edit/{property}', 'middleware' => ['property_permissions:edit']], function () {
            Route::get('/', ['uses' => 'PropertiesController@getProperty', 'middleware' => []])->name('editProperty');
            Route::post('/', [
                'uses' => 'PropertiesController@postProperty',
                'middleware' => []
            ])->name('postEditProperty');
            Route::get('/shares', [
                'uses' => 'PropertiesController@shares',
                'middleware' => []
            ])->name('editPropertyShares');
            // Log Data JSON fields
            Route::post('/log_data/{field}', [
                'uses' => 'PropertiesController@postLogData',
                'middleware' => []
            ])->name('postPropertyLogData');
            Route::get('/log_data/{field}', [
                'uses' => 'PropertiesController@getLogData',
                'middleware' => []
            ])->name('propertyLogData');

            // USED TO ALERT THE USER IF A PROPERTY HAS CHANGED WHILE THEY ARE VIEWING IT
            Route::post('/lastupdated', 'PropertiesController@lastUpdated');

            // UPLOADS/DELETES - EXCLUSIVELY TIED TO THE EDIT PERMISSION
            Route::post('/upload/file', ['uses' => 'PropertiesController@uploadFile', 'middleware' => []]);
            Route::post('/delete/file', ['uses' => 'PropertiesController@deleteFile', 'middleware' => []]);
            Route::post('/rename/file', ['uses' => 'PropertiesController@renameFile', 'middleware' => []]);
            Route::post('/upload/image', ['uses' => 'PropertiesController@uploadImage', 'middleware' => []]);
            Route::post('/delete/image', ['uses' => 'PropertiesController@deleteImage', 'middleware' => []]); // delete image from property photo tab
            Route::post('/rotate/image', ['uses' => 'PropertiesController@rotateImage', 'middleware' => []]); // rotate image in property photo tab
            Route::post('/crop/image', ['uses' => 'PropertiesController@cropImage', 'middleware' => []]); // crop image in property photo tab
            Route::post('/revert/image', ['uses' => 'PropertiesController@revertImage', 'middleware' => []]); // undo changes in property photo tab
            Route::post('/showAmenity/image', ['uses' => 'PropertiesController@showAmenityImage', 'middleware' => []]); // show property amenity for assign amenity link in property photo tab
            Route::post('/submitAmenity/image', ['uses' => 'PropertiesController@submitAmenityImage', 'middleware' => []]); // show property amenity for assign amenity link in property photo tab
            Route::post('/upload/brochure', ['uses' => 'PropertiesController@uploadBrochure', 'middleware' => []]);
            Route::post('/delete/brochure', ['uses' => 'PropertiesController@deleteBrochure', 'middleware' => []]);
            Route::post('/create/share', [
                'uses' => 'PropertiesController@createShare',
                'middleware' => ['permission:owncloud_share_create']
            ]);
            Route::post('/delete/share', [
                'uses' => 'PropertiesController@deleteShare',
                'middleware' => ['permission:owncloud_share_delete']
            ]);
        });

        // ADD
        Route::get('/add', [
            'uses' => 'PropertiesController@addProperty',
            'middleware' => ['permission:properties_add']
        ])->name('addProperty');
        Route::post('/add', [
            'uses' => 'PropertiesController@postProperty',
            'middleware' => ['permission:properties_add']
        ])->name('postAddProperty');

        // DISABLE / RESTORE / DELETE
        Route::post('/disable/{property}', [
            'uses' => 'PropertiesController@disableProperty',
            'middleware' => ['property_permissions:delete']
        ])->name('postDisableProperty');
        Route::post('/restore/{id}', [
            'uses' => 'PropertiesController@restoreProperty',
            'middleware' => ['role:super-user']
        ]);
        Route::post('/delete/{id}', [
            'uses' => 'PropertiesController@deleteProperty',
            'middleware' => ['role:super-user']
        ]);
    });


    /* ORDERS MODULE
     *
     * Order.php, OrderLineItem.php, OrdersController.php, OrderLineItemsController.php
     *
     * Permissions are controlled via the roles module and the order_permissions middleware
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view,
     *            via the roles module
     *     amend - suggest changes to a submitted order (OREF)
     *     review - review changes made to a submitted order (client)
     *     reassign - reassigns an order from one property to another
     *     cancel - stops an order; done from the client side
     *     reject - stops an order; done from the OREF side
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - hard deletes a record; super-user only
     */
    Route::group(['prefix' => '/orders', 'middleware' => ['permission:orders_view']], function () {

        // LIST
        Route::get('/', 'OrdersController@listOrders')->name('orders');

        // QUERY DATATABLE
        Route::post('/datatable', 'OrdersController@datatable');

        // SEARCH
        Route::get('/search/{search}', 'OrdersController@searchOrders')->name('searchOrders');

        // PULL ALLOWABLE AND AUTO-POPULATED CLINS FOR A GIVEN CLASS OF PROPERTY
        Route::post('/list/clins', ['uses' => 'OrdersController@listClins', 'middleware' => []])->name('listClins');

        // VIEW
        Route::group(['prefix' => '/view/{order}', 'middleware' => ['order_permissions:view']], function () {
            Route::get('/', 'OrdersController@getOrder')->name('viewOrder');
            Route::get('/download', ['uses' => 'OrdersController@download', 'middleware' => []]); // owncloud
            Route::get('/view', ['uses' => 'OrdersController@view', 'middleware' => []]); // owncloud

            // ALERT THE USER IF AN ORDER HAS CHANGED WHILE THEY ARE VIEWING IT
            Route::post('/lastupdated', 'OrdersController@lastUpdated');
        });
        Route::get('/download/{order}', 'OrdersController@downloadOrder')->name('downloadOrder'); // EXPORT TO PDF

        // EDIT
        Route::group([
            'prefix' => '/edit/{order}',
            'middleware' => ['order_permissions:edit', 'permission:orders_edit']
        ], function () {
            Route::get('/', ['uses' => 'OrdersController@getOrder', 'middleware' => []])->name('editOrder');
            Route::post('/', ['uses' => 'OrdersController@postOrder', 'middleware' => []])->name('postEditOrder');
            Route::post('/upload/file', ['uses' => 'OrdersController@upload', 'middleware' => []]);
            Route::post('/download', [
                'uses' => 'OrdersController@postOrder',
                'middleware' => []
            ])->name('postEditOrderPDF');
        });

        // REVIEW
        Route::group([
            'prefix' => '/review/{order}',
            'middleware' => ['order_permissions:review', 'permission:orders_review']
        ], function () {
            Route::get('/', ['uses' => 'OrdersController@getOrder', 'middleware' => []])->name('reviewOrder');
            Route::post('/', ['uses' => 'OrdersController@postOrder', 'middleware' => []])->name('postReviewOrder');
            Route::post('/upload/file', ['uses' => 'OrdersController@uploadFile', 'middleware' => []]);
        });

        // AMEND
        Route::group([
            'prefix' => '/amend/{order}',
            'middleware' => ['order_permissions:amend', 'permission:orders_amend']
        ], function () {
            Route::get('/', ['uses' => 'OrdersController@getOrder', 'middleware' => []])->name('amendOrder');
            Route::post('/', ['uses' => 'OrdersController@postOrder', 'middleware' => []])->name('postAmendOrder');
            Route::post('/upload/file', ['uses' => 'OrdersController@uploadFile', 'middleware' => []]);
        });

        // ADD
        Route::group(['prefix' => '/add', 'middleware' => ['permission:orders_add']], function () {
            Route::get('/', ['uses' => 'OrdersController@addOrder', 'middleware' => []])->name('addOrder');
            Route::post('/', ['uses' => 'OrdersController@postOrder', 'middleware' => []])->name('postAddOrder');
            Route::post('/download', [
                'uses' => 'OrdersController@postOrder',
                'middleware' => []
            ])->name('postAddOrderPDF');
        });

        // REASSIGN
        Route::post('/reassign/{order}', [
            'uses' => 'OrdersController@reassignOrder',
            'middleware' => ['order_permissions:reassign', 'permission:orders_reassign']
        ])->name('postReassignOrder');

        // CANCEL
        Route::post('/cancel/{order}', [
            'uses' => 'OrdersController@cancelOrder',
            'middleware' => ['order_permissions:cancel', 'permission:orders_cancel']
        ])->name('postCancelOrder');

        // REJECT
        Route::post('/reject/{order}', [
            'uses' => 'OrdersController@rejectOrder',
            'middleware' => ['order_permissions:reject', 'permission:orders_reject']
        ])->name('postRejectOrder');

        // DISABLE / RESTORE / DELETE
        Route::post('/disable/{order}', [
            'uses' => 'OrdersController@disableOrder',
            'middleware' => ['role:super-user']
        ])->name('postDisableOrder');
        Route::post('/restore/{id}', ['uses' => 'OrdersController@restoreOrder', 'middleware' => ['role:super-user']]);
        Route::post('/delete/{id}', ['uses' => 'OrdersController@deleteOrder', 'middleware' => ['role:super-user']]);


        /* LINE ITEMS
         *
         * Child to orders module.
         *
         * Actions:
         *     view - read-only access to a record
         *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view,
         *            via the roles module
         *     amend - suggest changes to a submitted order (OREF)
         *     review - review changes made to a submitted order (client)
         *     hold - temporarily disable a record from being edited, but leave it in list view
         *     release - re-enable a held record
         */
        Route::group(['prefix' => '/line_items', 'middleware' => []], function () {

            // LIST
            Route::get('/', 'OrderLineItemsController@listOrderLineItems')->name('orderLineItems');

            // QUERY DATATABLE
            Route::post('/datatable', 'OrderLineItemsController@datatable');

            // VIEW
            Route::group([
                'prefix' => '/view/{orderLineItem}',
                'middleware' => ['order_line_item_permissions:view']
            ], function () {
                Route::get('/', 'OrderLineItemsController@getOrderLineItem')->name('viewOrderLineItem');

                // ALERT THE USER IF AN ORDER LINE ITEM HAS CHANGED WHILE THEY ARE VIEWING IT
                Route::post('/lastupdated', 'OrderLineItemsController@lastUpdated');
            });

            // EDIT
            Route::group([
                'prefix' => '/edit/{orderLineItem}',
                'middleware' => ['order_line_item_permissions:edit', 'permission:order_line_items_edit']
            ], function () {
                Route::get('/', [
                    'uses' => 'OrderLineItemsController@getOrderLineItem',
                    'middleware' => []
                ])->name('editOrderLineItem');
                Route::post('/', [
                    'uses' => 'OrderLineItemsController@postOrderLineItem',
                    'middleware' => []
                ])->name('postEditOrderLineItem');
                Route::post('/create/share', [
                    'uses' => 'OrderLineItemsController@createShare',
                    'middleware' => ['permission:owncloud_share_create']
                ]);
            });

            // AMEND
            Route::group([
                'prefix' => '/amend/{orderLineItem}',
                'middleware' => ['order_line_item_permissions:amend', 'permission:order_line_items_amend']
            ], function () {
                Route::get('/', [
                    'uses' => 'OrderLineItemsController@getOrderLineItem',
                    'middleware' => []
                ])->name('amendOrderLineItem');
                Route::post('/', [
                    'uses' => 'OrderLineItemsController@postOrderLineItem',
                    'middleware' => []
                ])->name('postAmendOrderLineItem');
            });

            // REVIEW
            Route::group([
                'prefix' => '/review/{orderLineItem}',
                'middleware' => ['order_line_item_permissions:review', 'permission:order_line_items_review']
            ], function () {
                Route::get('/', [
                    'uses' => 'OrderLineItemsController@getOrderLineItem',
                    'middleware' => []
                ])->name('reviewOrderLineItem');
                Route::post('/', [
                    'uses' => 'OrderLineItemsController@postOrderLineItem',
                    'middleware' => []
                ])->name('postReviewOrderLineItem');
            });

            // ADD - CAN ONLY BE ADDED TO EXISTING ORDERS
            Route::group([
                'prefix' => '/add/{order}',
                'middleware' => ['order_line_item_permissions:add', 'permission:order_line_items_add']
            ], function () {
                Route::get('/', [
                    'uses' => 'OrderLineItemsController@addOrderLineItem',
                    'middleware' => []
                ])->name('addOrderLineItem');
                Route::post('/', [
                    'uses' => 'OrderLineItemsController@postOrderLineItem',
                    'middleware' => []
                ])->name('postAddOrderLineItem');
            });

            Route::get('/add', [
                'uses' => 'OrderLineItemsController@addOrderLineItem',
                'middleware' => ['permission:order_line_items_add']
            ])->name('addOrderLineItem');
            Route::post('/add', [
                'uses' => 'OrderLineItemsController@postOrderLineItem',
                'middleware' => ['permission:order_line_items_add']
            ])->name('postAddOrderLineItem');


            // HOLD / RELEASE
            Route::post('/hold/{orderLineItem}', [
                'uses' => 'OrderLineItemsController@holdOrderLineItem',
                'middleware' => ['order_line_item_permissions:hold']
            ])->name('holdOrderLineItem');
            Route::post('/release/{orderLineItem}', [
                'uses' => 'OrderLineItemsController@releaseOrderLineItem',
                'middleware' => ['order_line_item_permissions:release']
            ])->name('releaseOrderLineItem');

            // OBLIGATION REDIRECT
            Route::get('/obligation/{orderLineItemObligation}', [
                'uses' => 'OrderLineItemsController@obligationRedirect',
                'middleware' => ['permission:order_line_items_view']
            ])->name('orderLineItemObligationRedirect');
        });
    });


    /* INVOICES MODULE
     *
     * Invoice.php, InvoiceLineItem.php, InvoicePayment.php, InvoicesController.php
     *
     * Invoices go directly from ORE to the client. Invoice files are stored in an ownCloud sub-directory beneath the
     * parent task-order.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record
     *     hold - temporarily disable a record from being edited, but leave it in list view
     *     release - re-enable a held record
     *     reject - permanently disable a record, but leave it in list view
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - hard deletes a record; super-user only
     */
    Route::group(['prefix' => '/invoices', 'middleware' => ['permission:invoices_view']], function () {

        // LIST
        Route::get('/', 'InvoicesController@listInvoices')->name('invoices');

        // DATATABLE
        Route::post('/datatable', 'InvoicesController@datatable');

        // SEARCH
        Route::get('/search/{terms}', 'InvoicesController@searchInvoices')->name('searchInvoices');

        // JSON DATA
        Route::get('/json/{invoice?}', 'InvoicesController@formJSON')->name('invoiceFormJSON');

        // AUTOPOPULATE DATA
        Route::post('/autopopulate', 'InvoicesController@autopopulate')->name('invoiceFormAutopopulate');

        // DOWNLOAD EXCEL
        Route::get('/download/{invoice}', 'InvoicesController@download')->name('invoiceDownload');

        // VIEW
        Route::group(['prefix' => '/view/{invoice}', 'middleware' => []], function () {
            Route::get('/', 'InvoicesController@viewInvoice')->name('viewInvoice');
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/download', ['uses' => 'InvoicesController@downloadFile', 'middleware' => []]);
        });

        // EDIT
        Route::group(['prefix' => '/edit/{invoice}', 'middleware' => ['permission:invoices_edit']], function () {
            Route::get('/', ['uses' => 'InvoicesController@editInvoice', 'middleware' => []])->name('editInvoice');
            Route::post('/', ['uses' => 'InvoicesController@postInvoice', 'middleware' => []])->name('postEditInvoice');
            Route::post('/type', ['uses' => 'InvoicesController@updateType', 'middleware' => []]);
            Route::post('/upload/file', ['uses' => 'InvoicesController@uploadFile', 'middleware' => []]);
        });

        // INVOICE CHILDREN
        Route::group(['prefix' => '{invoice}'], function () {

            // SEARCH LINE ITEMS FOR GIVEN INVOICE
            Route::get('/search', 'InvoiceItemsController@search')->name('searchInvoiceItems');

            // PAGE TOTALS
            Route::get('/totals', 'InvoicesController@getTotals')->name('invoiceFormTotals');

            // INVOICE LINE ITEMS
            Route::group(['prefix' => '/items'], function () {
                Route::get('/page/{page}', ['uses' => 'InvoiceItemsController@getPage'])->name('getInvoiceItemPage');
                Route::post('/add', ['uses' => 'InvoiceItemsController@add'])->name('addInvoiceItem');
                Route::group(['prefix' => '/{invoice_line_item}'], function () {
                    Route::get('/', ['uses' => 'InvoiceItemsController@get'])->name('getInvoiceItem');
                    Route::post('/', ['uses' => 'InvoiceItemsController@edit'])->name('editInvoiceItem');
                    Route::post('/delete', ['uses' => 'InvoiceItemsController@delete'])->name('deleteInvoiceItem');

                    // INVOICE ITEM CREDITS
                    Route::group(['prefix' => '/credits'], function () {
                        Route::post('/add', ['uses' => 'InvoiceCreditsController@add'])->name('addInvoiceCredit');
                        Route::group(['prefix' => '/{invoice_credit}'], function () {
                            Route::get('/', ['uses' => 'InvoiceCreditsController@get'])->name('getInvoiceCredit');
                            Route::post('/', ['uses' => 'InvoiceCreditsController@edit'])->name('editInvoiceCredit');
                            Route::post('/delete', ['uses' => 'InvoiceCreditsController@delete'])
                                ->name('deleteInvoiceCredit');
                        });
                    });
                });
            });

            // INVOICE PAYMENTS
            Route::group(['prefix' => '/payments'], function () {
                Route::post('/add', ['uses' => 'InvoicePaymentsController@add'])->name('addInvoicePayment');
                Route::group(['prefix' => '/{invoice_payment}'], function () {
                    Route::get('/', ['uses' => 'InvoicePaymentsController@get'])->name('getInvoicePayment');
                    Route::post('/', ['uses' => 'InvoicePaymentsController@edit'])->name('editInvoicePayment');
                    Route::post('/delete', ['uses' => 'InvoicePaymentsController@delete'])
                        ->name('deleteInvoicePayment');
                });
            });
        });

        // ADD
        Route::get('/add', [
            'uses' => 'InvoicesController@addInvoice',
            'middleware' => ['permission:invoices_add']
        ])->name('addInvoice');
        Route::post('/add', [
            'uses' => 'InvoicesController@postInvoice',
            'middleware' => ['permission:invoices_add']
        ])->name('postAddInvoice');

        //DISABLE / RESTORE / DELETE
        Route::post('/disable/{invoice}', [
            'uses' => 'InvoicesController@disableInvoice',
            'middleware' => ['permission:invoices_disable']
        ])->name('disableInvoice');
        Route::post('/restore/{id}', [
            'uses' => 'InvoicesController@restoreInvoice',
            'middleware' => ['permission:invoices_restore']
        ])->name('restoreInvoice');
        Route::post('/delete/{id}', [
            'uses' => 'InvoicesController@deleteInvoice',
            'middleware' => ['permission:invoices_delete']
        ])->name('deleteInvoice');
    });


    /* VENDOR BILLS MODULE
     *
     * VendorBill.php, VendorBillLineItem.php, VendorBillsController.php
     *
     * Vendors submit bills for services rendered. Vendor bill files are stored in an ownCloud sub-directory beneath
     * the parent vendor.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record
     *     review - review and approve/reject changes to a record
     *     hold - temporarily disable a record from being edited, but leave it in list view
     *     release - re-enable a held record
     *     reject - permanently disable a record, but leave it in list view
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - hard deletes a record; super-user only
     */
    Route::group(['prefix' => '/vendor_bills', 'middleware' => ['permission:vendor_bills_view']], function () {

        // SEARCH
        Route::get('/search/{search}', 'VendorBillsController@searchVendorBills')->name('searchVendorBills');

        // QUERY DATATABLE
        Route::post('/datatable', 'VendorBillsController@datatable');

        // VIEW
        Route::group(['prefix' => '/view/{vendorBill}', 'middleware' => []], function () {
            Route::get('/', 'VendorBillsController@getVendorBill')->name('viewVendorBill');
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/download', ['uses' => 'VendorBillsController@downloadFile', 'middleware' => []]);
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/view', ['uses' => 'VendorBillsController@viewFile', 'middleware' => []]);
        });
        // EXPORT TO PDF
        Route::get('/download/{vendorBill}', 'VendorBillsController@downloadVendorBill')->name('downloadVendorBill');

        // EDIT
        Route::group(['prefix' => '/edit/{vendorBill}', 'middleware' => ['vendor_bill_permissions:edit']], function () {
            Route::get('/', [
                'uses' => 'VendorBillsController@getVendorBill',
                'middleware' => []
            ])->name('editVendorBill');
            Route::post('/', [
                'uses' => 'VendorBillsController@postVendorBill',
                'middleware' => []
            ])->name('postEditVendorBill');
            Route::post('/download', [
                'uses' => 'VendorBillsController@postVendorBill',
                'middleware' => []
            ])->name('postEditVendorBillPDF');
            Route::post('/upload/file', ['uses' => 'VendorBillsController@uploadFile', 'middleware' => []]);
        });

        // REVIEW
        Route::group([
            'prefix' => '/review/{vendorBill}',
            'middleware' => ['vendor_bill_permissions:review']
        ], function () {
            Route::get('/', [
                'uses' => 'VendorBillsController@getVendorBill',
                'middleware' => []
            ])->name('reviewVendorBill');
            Route::post('/', [
                'uses' => 'VendorBillsController@postVendorbill',
                'middleware' => []
            ])->name('postReviewVendorBill');
            Route::post('/download', [
                'uses' => 'VendorBillsController@postVendorBill',
                'middleware' => []
            ])->name('postReviewVendorBillPDF');
            Route::post('/upload/file', ['uses' => 'VendorBillsController@uploadFile', 'middleware' => []]);
        });

        // REMEDIATE
        Route::group([
            'prefix' => '/remediate/{vendorBill}',
            'middleware' => ['vendor_bill_permissions:remediate']
        ], function () {
            Route::get('/', [
                'uses' => 'VendorBillsController@getVendorBill',
                'middleware' => []
            ])->name('remediateVendorBill');
            Route::post('/', [
                'uses' => 'VendorBillsController@postVendorbill',
                'middleware' => []
            ])->name('postRemediateVendorBill');
            Route::post('/download', [
                'uses' => 'VendorBillsController@postVendorBill',
                'middleware' => []
            ])->name('postRemediateVendorBillPDF');
            Route::post('/upload/file', ['uses' => 'VendorBillsController@uploadFile', 'middleware' => []]);
        });

        // PAY
        Route::group(['prefix' => '/pay/{vendorBill}', 'middleware' => ['vendor_bill_permissions:pay']], function () {
            Route::get('/', [
                'uses' => 'VendorBillsController@getVendorBill',
                'middleware' => []
            ])->name('payVendorBill');
            Route::post('/', [
                'uses' => 'VendorBillsController@postVendorBill',
                'middleware' => []
            ])->name('postPayVendorBill');
            Route::post('/download', [
                'uses' => 'VendorBillsController@postVendorBill',
                'middleware' => []
            ])->name('postPayVendorBillPDF');
            Route::post('/upload/file', ['uses' => 'VendorBillsController@uploadFile', 'middleware' => []]);
        });


        // ADD
        Route::get('/add', [
            'uses' => 'VendorBillsController@addVendorBill',
            'middleware' => ['permission:vendor_bills_add']
        ])->name('addVendorBill');
        Route::post('/add', [
            'uses' => 'VendorBillsController@postVendorBill',
            'middleware' => ['permission:vendor_bills_add']
        ])->name('postAddVendorBill');
        Route::post('/add/download', [
            'uses' => 'VendorBillsController@postVendorBill',
            'middleware' => ['permission:vendor_bill_add']
        ])->name('postAddVendorBillPDF');

        //DISABLE / RESTORE / DELETE
        Route::post('/disable/{vendorBill}', [
            'uses' => 'VendorBillsController@disableVendorBill',
            'middleware' => ['vendor_bill_permissions:disable']
        ])->name('disableVendorBill');
        Route::post('/restore/{id}', [
            'uses' => 'VendorBillsController@restoreVendorBill',
            'middleware' => ['vendor_bill_permissions:restore']
        ])->name('restoreVendorBill');
        Route::post('/delete/{id}', [
            'uses' => 'VendorBillsController@deleteVendorBill',
            'middleware' => ['vendor_bill_permissions:delete']
        ])->name('deleteVendorBill');

        //APPROVE
        Route::group(['prefix' => '/approve', 'middleware' => ['permission:vendor_bills_approve']], function () {
            Route::get('/', 'VendorBillsController@listVendorBillApproval')->name('vendorBillApproval');
            // QUERY DATATABLE
            Route::post('/datatable', 'VendorBillsController@datatableApprove');
            //APPROVE ROUTE
            Route::post('/{status?}', 'VendorBillsController@submitBulkApproval')->name('vendorBillApprovalSubmit');
        });

        //Settle
        Route::group([
            'prefix' => '/',
            'middleware' => ['vendor_bill_permissions:settle'],
            ['permission:vendor_bills_settle']
        ], function () {
            Route::get('/settle/{vendorBill}', 'VendorBillsController@getVendorBill')->name('settleVendorBill');
            Route::post('/reject/{vendorBill}', 'VendorBillsController@rejectVendorBill');
        });

        // LIST - OPTIONAL DAYS PARAMETER CUTS DOWN ON VOLUME OF ROWS - KEPT AT THE END, SINCE IT'S A CATCHALL
        Route::get('/{days?}', 'VendorBillsController@listVendorBills')->name('vendorBills');
    });


    /* VENDORS MODULE
     *
     * Vendor.php, VendorsController.php
     *
     * 'vendors->code' determines the path in OwnCloud for this vendor. All bills from this vendor will be under this
     * path.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view, via
     *            the roles module
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - hard deletes a record; super-user only
     */
    Route::group(['prefix' => '/vendors', 'middleware' => ['permission:vendors_view']], function () {

        // LIST
        Route::get('/', 'VendorsController@listVendors')->name('vendors');

        // QUERY DATATABLE
        Route::post('/datatable', 'VendorsController@datatable');

        // VIEW
        Route::group(['prefix' => '/view/{vendor}', 'middleware' => []], function () {
            Route::get('/', 'VendorsController@getVendor')->name('viewVendor');
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/download', ['uses' => 'VendorsController@downloadFile', 'middleware' => []]);
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/view', ['uses' => 'VendorsController@viewFile', 'middleware' => []]);
        });

        // EDIT
        Route::group(['prefix' => '/edit/{vendor}', 'middleware' => ['permission:vendors_edit']], function () {
            Route::get('/', ['uses' => 'VendorsController@getVendor', 'middleware' => []])->name('editVendor');
            Route::post('/', ['uses' => 'VendorsController@postVendor', 'middleware' => []])->name('postEditVendor');
            Route::post('/upload/file', ['uses' => 'VendorsController@uploadFile', 'middleware' => []]);
        });

        // ADD
        Route::get('/add', [
            'uses' => 'VendorsController@addVendor',
            'middleware' => ['permission:vendors_add']
        ])->name('addVendor');
        Route::post('/add', [
            'uses' => 'VendorsController@postVendor',
            'middleware' => ['permission:vendors_add']
        ])->name('postAddVendor');

        // DISABLE / RESTORE / DELETE
        Route::post('/disable/{vendor}', [
            'uses' => 'VendorsController@disableVendor',
            'middleware' => ['permission:vendors_delete']
        ])->name('deleteVendor');
        Route::post('/restore/{id}', [
            'uses' => 'VendorsController@restoreVendor',
            'middleware' => ['role:super-user']
        ])->name('restoreVendor');
        Route::post('/delete/{id}', ['uses' => 'VendorsController@deleteVendor', 'middleware' => ['role:super-user']]);
    });


    /* ROLES MODULE
     *
     * Role.php, RolesController.php
     *
     * Site permissions are controlled on a per-role basis. Permissions are stored in the 'permissions' table and
     *     controlled using the 'Zizaco\Entrust' package (https://github.com/Zizaco/entrust).
     *
     *     Note: Permission failures, by default, result in a 403. I have manually modified 'app/Exceptions/Handler.php'
     *           to catch 403 responses and silently redirect to the previous page.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view, via
     *            the roles module
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - TODO: add hard deletes
     */
    Route::group(['prefix' => '/roles', 'middleware' => ['permission:roles_view']], function () {

        // LIST
        Route::get('/', 'RolesController@listRoles')->name('roles');

        // VIEW
        Route::get('/view/{role}', 'RolesController@getRole')->name('viewRole');

        // EDIT
        Route::get('/edit/{role}', [
            'uses' => 'RolesController@getRole',
            'middleware' => ['permission:roles_edit']
        ])->name('editRole');
        Route::post('/edit/{role}', [
            'uses' => 'RolesController@postRole',
            'middleware' => ['permission:roles_edit']
        ])->name('postEditRole');

        // ADD
        Route::get('/add', [
            'uses' => 'RolesController@addRole',
            'middleware' => ['permission:roles_add']
        ])->name('addRole');
        Route::post('/add', [
            'uses' => 'RolesController@postRole',
            'middleware' => ['permission:roles_add']
        ])->name('postAddRole');

        // DISABLE / RESTORE
        Route::post('/disable/{role}', [
            'uses' => 'RolesController@disableRole',
            'middleware' => ['permission:roles_delete']
        ])->name('deleteRole');
        Route::post('/restore/{id}', [
            'uses' => 'RolesController@restoreRole',
            'middleware' => ['role:super-user']
        ])->name('restoreRole');
        Route::post('/delete/{id}', [
            'uses' => 'RolesController@deleteRole',
            'middleware' => ['role:super-user']
        ])->name('deleteRole');
    });


    /* GROUPS MODULE
     *
     * Group.php, GroupsController.php
     *
     * Referred to as a District for USMS-Track. Every property belongs to a group. Determines the default
     *     'property operations manager' contact for all properties in the group, and can be used to reassign p.o.m.
     *     for all properties in the group.
     *
     * 'groups->code' determines the path in OwnCloud for this group. All assigned assets will be under this path.
     *
     * A group may be assigned to multiple contracts by way of the 'Contracts' tab.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view, via
     *            the roles module
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - TODO: add hard deletes
     */
    Route::group(['prefix' => '/groups', 'middleware' => ['permission:groups_view']], function () {

        // LIST
        Route::get('/', 'GroupsController@listGroups')->name('groups');

        // QUERY DATATABLE
        Route::post('/datatable', 'GroupsController@datatable');

        // VIEW
        Route::group(['prefix' => '/view/{group}', 'middleware' => []], function () {
            Route::get('/', 'GroupsController@getGroup')->name('viewGroup');
            // OWNCLOUD FILE DOWNLOADS
            Route::get('/download', ['uses' => 'GroupsController@download', 'middleware' => []]);
            // OWNCLOUD FILE VIEWER
            Route::get('/view', ['uses' => 'GroupsController@view', 'middleware' => []]);
        });

        // EDIT
        Route::group(['prefix' => '/edit/{group}', 'middleware' => ['permission:groups_edit']], function () {
            Route::get('/', ['uses' => 'GroupsController@getGroup', 'middleware' => []])->name('editGroup');
            Route::post('/', ['uses' => 'GroupsController@postGroup', 'middleware' => []])->name('postEditGroup');
            Route::post('/upload/file', ['uses' => 'GroupsController@upload', 'middleware' => []]);
        });

        // ADD
        Route::get('/add', [
            'uses' => 'GroupsController@addGroup',
            'middleware' => ['permission:groups_add']
        ])->name('addGroup');
        Route::post('/add', [
            'uses' => 'GroupsController@postGroup',
            'middleware' => ['permission:groups_add']
        ])->name('postAddGroup');

        // DISABLE / RESTORE
        Route::post('/disable/{group}', [
            'uses' => 'GroupsController@disableGroup',
            'middleware' => ['permission:groups_delete']
        ])->name('deleteGroup');
        Route::post('/restore/{id}', [
            'uses' => 'GroupsController@restoreGroup',
            'middleware' => ['role:super-user']
        ])->name('restoreGroup');
    });


    /* CONTRACTS MODULE
     *
     * Contract.php, ContractsController.php
     * This determines the total budget for a given set of properties.
     */
    Route::group(['prefix' => '/contracts', 'middleware' => ['role:super-user']], function () {

        // LIST
        Route::get('/', 'ContractsController@list')->name('contracts');

        // QUERY DATATABLE
        Route::post('/datatable', 'ContractsController@datatable');

        // VIEW
        Route::group(['prefix' => '/view/{contract}', 'middleware' => []], function () {
            Route::get('/', 'ContractsController@get')->name('viewContract');
        });

        //ADD
        Route::get('/add', ['uses' => 'ContractsController@add', 'middleware' => []])->name('addContract');
        Route::post('/add', ['uses' => 'ContractsController@store', 'middleware' => []])->name('postContract');

        // EDIT
        Route::group(['prefix' => '/edit/{contract}', 'middleware' => ['role:super-user']], function () {
            Route::get('/', ['uses' => 'ContractsController@get', 'middleware' => []])->name('editContract');
            Route::post('/', ['uses' => 'ContractsController@update', 'middleware' => []])->name('postEditContract');

            /* CONTRACT PERIODS MODULE
             *
             * ContractPeriod.php, ContractPeriodsController.php
             * Time delineated parts of a contract
             */
            Route::group(['prefix' => '/periods', 'middleware' => []], function () {
                Route::get('/', ['uses' => 'ContractPeriodsController@index', 'middleware' => []])->name('getPeriods');
                Route::post('/add', [
                    'uses' => 'ContractPeriodsController@store',
                    'middleware' => []
                ])->name('storePeriod');

                // EDIT
                Route::group(['prefix' => '/{period}', 'middleware' => []], function () {
                    Route::get('/', ['uses' => 'ContractPeriodsController@get', 'middleware' => []])->name('getPeriod');
                    Route::post('/', [
                        'uses' => 'ContractPeriodsController@update',
                        'middleware' => []
                    ])->name('updatePeriod');

                    // DELETE
                    Route::post('/delete', [
                        'uses' => 'ContractPeriodsController@delete',
                        'middleware' => []
                    ])->name('deletePeriod');

                    /* CONTRACT LINE ITEM MODULE
                     *
                     * ContractLineItem.php, ContractLineItemsController.php
                     * CLIN
                     */
                    Route::group(['prefix' => '/contract_line_items', 'middleware' => []], function () {
                        // ADD
                        Route::post('/add', [
                            'uses' => 'ContractLineItemsController@store',
                            'middleware' => []
                        ])->name('storeContractLineItem');

                        Route::group(['prefix' => '/{contract_line_item}', 'middleware' => []], function () {
                            // GET
                            Route::get('/', [
                                'uses' => 'ContractLineItemsController@get',
                                'middleware' => []
                            ])->name('getContractLineItem');

                            // EDIT
                            Route::post('/', [
                                'uses' => 'ContractLineItemsController@update',
                                'middleware' => []
                            ])->name('updateContractLineItem');

                            // DELETE
                            Route::post('/delete', [
                                'uses' => 'ContractLineItemsController@delete',
                                'middleware' => []
                            ])->name('deleteContractLineItem');
                        });
                    });
                });
            });
        });
    });



    /* MAPPINGS MODULE
     *
     * Mapping.php, MappingsController.php
     *
     * Mappings are used to override the default titles field, section, and tab titles in the property views. It can
     *     also be used to hide fields, sections, or tabs entirely. This allows OREF to customize the system on a
     *     per-client basis. Currently limited to the properties and groups modules.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view, via
     *            the roles module
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - TODO: add hard deletes
     */
    Route::group(['prefix' => '/mappings', 'middleware' => ['permission:mappings_view']], function () {

        // LIST
        Route::get('/', 'MappingsController@listMappings')->name('mappings');

        // VIEW
        Route::get('/view/{mapping}', 'MappingsController@getMapping')->name('viewMapping');

        // EDIT
        Route::get('/edit/{mapping}', [
            'uses' => 'MappingsController@getMapping',
            'middleware' => ['permission:mappings_edit']
        ])->name('editMapping');
        Route::post('/edit/{mapping}', [
            'uses' => 'MappingsController@postMapping',
            'middleware' => ['permission:mappings_edit']
        ])->name('postEditMapping');

        // ADD
        Route::get('/add', [
            'uses' => 'MappingsController@addMapping',
            'middleware' => ['permission:mappings_add']
        ])->name('addMapping');
        Route::post('/add', [
            'uses' => 'MappingsController@postMapping',
            'middleware' => ['permission:mappings_add']
        ])->name('postAddMapping');

        // DISABLE / RESTORE
        Route::post('/disable/{mapping}', [
            'uses' => 'MappingsController@disableMapping',
            'middleware' => ['permission:mappings_delete']
        ])->name('deleteMapping');
        Route::post('/restore/{id}', [
            'uses' => 'MappingsController@restoreMapping',
            'middleware' => ['role:super-user']
        ])->name('restoreMapping');
    });


    /* REPORTS MODULE
     *
     * ReportsController.php
     *
     * Actions:
     *     download - reports are typically downloaded as Excel spreadsheets.
     *     overlay - accepts a CSV, XLS, or XLSX file as input and overwrite table data based on the content uploaded.
     */
    Route::group(['prefix' => '/reports', 'middleware' => ['permission:reports_view']], function () {
 //, 'role:superuser'

        // REPORTS
        Route::get('/', 'ReportsController@getView')->name('reports');
        Route::get('/tasks', [
            'uses' => 'ReportsController@getTasks',
            'middleware' => ['permission:reports_tasks']
        ])->name('reportsTasks');
        //Route::post('/all-data-report', [
        //'uses' => 'ReportsController@allDataReportNew',
        // 'middleware' => ['permission:reports_all_data_report']
        //]);
        Route::post('/bill-data-report', [
            'uses' => 'ReportsController@billDataExport',
            'middleware' => ['permission:reports_bill_data_report']
        ]);
        Route::post('/vendor-bills-export', [
            'uses' => 'ReportsController@vendorBillsExport',
            'middleware' => ['permission:reports_vendor_bills']
        ]);
        Route::post('/marketing-status-report', [
            'uses' => 'ReportsController@marketingStatusReport',
            'middleware' => ['permission:reports_marketing_status_report']
        ]);
        Route::post('/title-report', [
            'uses' => 'ReportsController@titleReport',
            'middleware' => ['permission:reports_title']
        ])->name('titleReport');
        Route::post('/valuation-report', [
            'uses' => 'ReportsController@valuationReport',
            'middleware' => ['permission:reports_valuation']
        ])->name('valuationReport');
        Route::post('/deliverable-status-list-report', [
            'uses' => 'ReportsController@deliverableStatusListReport',
            'middleware' => ['permission:reports_deliverable_status_list']
        ])->name('deliverableStatusListReport');
        Route::post('/disposition-report', [
            'uses' => 'ReportsController@dispositionReport',
            'middleware' => ['permission:reports_disposition']
        ])->name('dispositionReport');
        Route::post('/custody-report', [
            'uses' => 'ReportsController@custodyReport',
            'middleware' => ['permission:reports_custody']
        ])->name('custodyReport');
        Route::post('/survey-report', [
            'uses' => 'ReportsController@surveyReport',
            'middleware' => ['permission:reports_survey']
        ])->name('surveyReport');
        Route::post('/property-operations-report', [
            'uses' => 'ReportsController@propertyOperationsReport',
            'middleware' => ['permission:reports_property_operations']
        ])->name('propertyOperationsReport');
        Route::post('/orders-summary-report', [
            'uses' => 'ReportsController@ordersSummaryReport',
            'middleware' => ['permission:reports_orders_summary']
        ])->name('ordersSummaryReport');
        Route::post('/missing-disposition-report', [
            'uses' => 'ReportsController@missingDispositionReport',
            'middleware' => ['permission:reports_missing_disposition']
        ])->name('missingDispositionReport');
        Route::post('/lockbox-changes-report', [
            'uses' => 'ReportsController@lockboxChangesReport',
            'middleware' => ['permission:reports_lockbox_changes']
        ])->name('lockboxChangesReport');
        Route::post('/owncloud-shares-report', [
            'uses' => 'ReportsController@owncloudSharesReport',
            'middleware' => ['permission:reports_owncloud_shares']
        ])->name('owncloudSharesReport');
        Route::post('/overdue-tasks-report', [
            'uses' => 'ReportsController@overdueTasksReport',
            'middleware' => ['permission:reports_overdue_tasks']
        ])->name('overdueTasksReport');
        Route::post('/filer-report', [
            'uses' => 'ReportsController@filerReport',
            'middleware' => ['permission:reports_filer']
        ])->name('filerReport');
        Route::post('/pre-seizure-recommendation-summary', [
            'uses' => 'ReportsController@preSeizureRecommendationSummary',
            'middleware' => ['permission:reports_pre-seizure_recommendation_summary']
        ])->name('preSeizureRecommendationSummary');
        Route::post('/budget-report', [
            'uses' => 'ReportsController@budgetReport',
            'middleware' => ['permission:reports_budget']
        ])->name('budgetReport');
        Route::post('/overview', [
            'uses' => 'ReportsController@overviewReport',
            'middleware' => ['permission:reports_overview']
        ])->name('overviewReport');
        Route::post('/overview/benchmark', [
            'uses' => 'ReportsController@overviewReport',
            'middleware' => ['permission:reports_overview']
        ])->name('overviewReportBenchmark');
        Route::post('/incomplete-title-orders', [
            'uses' => 'ReportsController@incompleteTitleOrdersReport',
            'middleware' => ['permission:reports_incomplete_title_orders']
        ])->name('incompleteTitleOrdersReport');
        Route::post('/incomplete-valuation-orders', [
            'uses' => 'ReportsController@incompleteValuationOrdersReport',
            'middleware' => ['permission:reports_incomplete_valuation_orders']
        ])->name('incompleteValuationOrdersReport');
        Route::post('/inspection-list', [
            'uses' => 'ReportsController@inspectionListReport',
            'middleware' => ['permission:reports_inspection_list']
        ])->name('inspectionListReport');
        Route::post('/inspection-status', [
            'uses' => 'ReportsController@inspectionStatusReport',
            'middleware' => ['permission:reports_inspection_status']
        ])->name('inspectionStatusReport');
        Route::post('/title-status', [
            'uses' => 'ReportsController@titleStatusReport',
            'middleware' => ['permission:reports_title_status']
        ])->name('titleStatusReport');
        Route::post('/asset-list-report', [
            'uses' => 'ReportsController@assetListReport',
            'middleware' => ['permission:reports_asset_list']
        ])->name('assetListReport');
        Route::post('/bill-audit-report', [
            'uses' => 'ReportsController@billAuditReport',
            'middleware' => ['permission:reports_bill_audit']
        ])->name('billAuditReport');
        Route::post('/commissions-report', [
            'uses' => 'ReportsController@commissionsReport',
            'middleware' => ['permission:reports_commissions']
        ])->name('commissionsReport');
        Route::post('/occupied-properties-report', [
            'uses' => 'ReportsController@occupiedPropertiesReport',
            'middleware' => ['permission:reports_occupied_properties']
        ])->name('occupiedPropertiesReport');
        Route::post('/vendor-bill-file-export', [
            'uses' => 'ReportsController@vendorBillFileExport',
            'middleware' => ['permission:reports_vendor_bill_file_export']
        ]);
        Route::post('/vendor-bill-file-export-by-asset', [
            'uses' => 'ReportsController@vendorBillFileExportByAsset',
            'middleware' => ['permission:reports_vendor_bill_file_export']
        ]);
        Route::post('/vendor-bill-file-export-by-vendor', [
            'uses' => 'ReportsController@vendorBillFileExportByVendor',
            'middleware' => ['permission:reports_vendor_bill_file_export']
        ]);
        Route::post('/vendor-bill-audit-report', [
            'uses' => 'ReportsController@vendorBillAuditReport',
            'middleware' => ['permission:reports_vendor_bill_overlay']
        ])->name('vendorBillAuditReport');
        Route::post('/rejected-vendor-bill-report', [
            'uses' => 'ReportsController@clientRejectedVendorBillsReport',
            'middleware' => ['permission:reports_rejected_vendor_bill']
        ])->name('rejectedVendorBillsReport');
        Route::post('/overspent-clins-report', [
            'uses' => 'ReportsController@overSpentClinsReport',
            'middleware' => ['permission:reports_overspent_clins']
        ]);
        Route::post('/contract-periods-export', [
            'uses' => 'ReportsController@contractPeriodsReport',
            'middleware' => ['permission:reports_contract_periods']
        ]);
        Route::post('/recently-closed-out-properties-report', [
            'uses' => 'ReportsController@recentlyClosedOutPropertiesReport',
            'middleware' => ['permission:reports_recently_closed_out_properties']
        ])->name('recentlyClosedOutPropertiesReport');
        Route::post('/third-party-property-management-report', [
            'uses' => 'ReportsController@thirdPartyPropertyManagementReport',
            'middleware' => ['permission:reports_third_party_property_management']
        ])->name('thirdPartyPropertyManagementReport');
        Route::post('/order-line-item-obligations-audit', [
            'uses' => 'ReportsController@orderLineItemObligationsAudit',
            'middleware' => ['role:super-user']
        ])->name('orderLineItemObligationsAudit');
        Route::post('/order-line-item-obligations-report', [
            'uses' => 'ReportsController@orderLineItemObligationsReport',
            'middleware' => ['role:super-user']
        ])->name('orderLineItemObligationsReport');

        // OVERLAYS
        Route::post('/property-overlay', [
            'uses' => 'ReportsController@propertyOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/contact-overlay', [
            'uses' => 'ReportsController@contactOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/contact-overlay/template', [
            'uses' => 'ReportsController@contactOverlayTemplate',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        //Route::post('/sto-overlay', [
        //'uses' => 'ReportsController@stoOverlay',
        // 'middleware' => ['permission:reports_csv_overlay']
        //]);
        Route::post('/order-line-item-overlay-legacy', [
            'uses' => 'ReportsController@orderLineItemOverlayLegacy',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/order-line-item-overlay', [
            'uses' => 'ReportsController@orderLineItemOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/order-line-item-overlay/template', [
            'uses' => 'ReportsController@orderLineItemOverlayTemplate',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/order-line-obligation-overlay', [
            'uses' => 'ReportsController@orderLineItemObligationOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/order-line-obligation-overlay/template', [
            'uses' => 'ReportsController@orderLineItemOverlayTemplate',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/vendor-bill-overlay', [
            'uses' => 'ReportsController@vendorBillOverlay',
            'middleware' => ['permission:reports_vendor_bill_overlay']
        ]);
        Route::get('/vendor-bill-overlay/template', [
            'uses' => 'ReportsController@vendorBillOverlayTemplate',
            'middleware' => ['permission:reports_vendor_bill_overlay']
        ]);
        Route::post('/vendor-bill-payment-overlay', [
            'uses' => 'ReportsController@vendorBillPaymentOverlay',
            'middleware' => ['permission:reports_vendor_bill_overlay']
        ]);
        Route::get('/vendor-bill-payment-overlay/template', [
            'uses' => 'ReportsController@vendorBillPaymentOverlayTemplate',
            'middleware' => ['permission:reports_vendor_bill_overlay']
        ]);
        Route::post('/property-file-renamer-overlay', [
            'uses' => 'ReportsController@propertyFileRenamerOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/amenity-overlay', [
            'uses' => 'ReportsController@amenityOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/amenity-overlay/template', [
            'uses' => 'ReportsController@amenityOverlayTemplate',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/notes-overlay', [
            'uses' => 'ReportsController@notesOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/notes-overlay/template', [
            'uses' => 'ReportsController@notesOverlayTemplate',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/files-overlay', [
            'uses' => 'ReportsController@filesOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        Route::post('/vendor-bill-to-clin-overlay', [
            'uses' => 'ReportsController@vendorBillToClinOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
        //Route::post('/inspection-log-overlay', [
        //'uses' => 'ReportsController@inspectionLogOverlay',
        // 'middleware' => ['permission:reports_csv_overlay'
        //]]);
        Route::post('/inspection-billed-date-overlay', [
            'uses' => 'ReportsController@inspectionBilledDateOverlay',
            'middleware' => ['permission:reports_csv_overlay']
        ]);
    });


    /* LOG MODULE
     *
     * LogsController.php
     *
     * Actions:
     *     List - Return a list of logs
     */
    Route::group(['middleware' => ['role:super-user']], function () {
        Route::get('logs/last', 'LogsController@last');
        Route::get('logs/poll', 'LogsController@poll');
        Route::post('logs/datatable', 'LogsController@datatable');
        // LOGS Resource Routes
        Route::resource('logs', 'LogsController')->only([
            'index', 'show'
        ]);
    });

    /* AMENITIES MODULE
     *
     * Amenity.php, AmenitiesController.php
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record; additional permissions are assigned for each tab in the properties view, via
     *            the roles module
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - TODO: add hard deletes
     */
    Route::group(['prefix' => '/amenities', 'middleware' => ['permission:amenities_view']], function () {

        // LIST
        Route::get('/', 'AmenitiesController@listAmenities')->name('amenities');

        // VIEW
        Route::get('/view/{amenity}', 'AmenitiesController@getAmenity')->name('viewAmenity');

        // EDIT
        Route::get('/edit/{amenity}', [
            'uses' => 'AmenitiesController@getAmenity',
            'middleware' => ['permission:amenities_edit']
        ])->name('editAmenity');
        Route::post('/edit/{amenity}', [
            'uses' => 'AmenitiesController@postAmenity',
            'middleware' => ['permission:amenities_edit']
        ])->name('postEditAmenity');

        // ADD
        Route::get('/add', [
            'uses' => 'AmenitiesController@addAmenity',
            'middleware' => ['permission:amenities_add']
        ])->name('addAmenity');
        Route::post('/add', [
            'uses' => 'AmenitiesController@postAmenity',
            'middleware' => ['permission:amenities_add']
        ])->name('postAddAmenity');

        // DISABLE / RESTORE
        Route::post('/disable/{amenity}', [
            'uses' => 'AmenitiesController@disableAmenity',
            'middleware' => ['permission:amenities_delete']
        ])->name('deleteAmenity');
        Route::post('/restore/{id}', [
            'uses' => 'AmenitiesController@restoreAmenity',
            'middleware' => ['role:super-user']
        ])->name('restoreAmenity');
        Route::post('/delete/{id}', [
            'uses' => 'AmenitiesController@deleteAmenity',
            'middleware' => ['role:super-user']
        ])->name('deleteAmenity');
    });

    /* VIDEOS MODULE
     *
     * Video.php, VideosController.php
     *
     * 'videos' table stores video embed information such as title, embed_code, required, etc.
     *
     * Actions:
     *     view - read-only access to a record
     *     edit - edit access to a record
     *     disable - soft delete a record
     *     restore - un-deletes a record; super-user only
     *     delete - TODO: add hard deletes
     */
    Route::group(['prefix' => '/videos', 'middleware' => ['permission:support_access_videos']], function () {
        // LIST
        Route::get('/', 'VideosController@listVideos')->name('videos');

        // LIST REQUIRED VIDEOS
        Route::get('/required', 'VideosController@listRequiredVideos')->name('requiredVideos');

        // VIEW
        Route::get('/view/{video}', 'VideosController@getVideo')->name('viewVideo');

        Route::group(['middleware' => ['role:super-user']], function () {
            // EDIT
            Route::get('/edit/{video}', ['uses' => 'VideosController@editVideo'])->name('editVideo');
            Route::post('/edit/{video}', ['uses' => 'VideosController@postVideo'])->name('postEditVideo');

            // ADD
            Route::get('/add', ['uses' => 'VideosController@addVideo'])->name('addVideo');
            Route::post('/add', ['uses' => 'VideosController@postVideo'])->name('postAddVideo');

            // DISABLE / RESTORE
            Route::post('/disable/{video}', ['uses' => 'VideosController@disableVideo'])->name('disableVideo');
            Route::post('/restore/{id}', ['uses' => 'VideosController@restoreVideo'])->name('restoreVideo');
            Route::post('/delete/{id}', ['uses' => 'VideosController@deleteVideo'])->name('deleteVideo');
        });
    });
});
