@section('modal')
    <script type="text/javascript">
        $(document).ready(function() {

            {{-- GLOBAL DIALOGS --}}
            // ATTACH THE MODAL DISABLE HANDLER
            $(document).on("click", ".confirmDisable", function() {
                var path = $(this).data('path');
                var name = $(this).data('name');
                var id = $(this).data('id');
                $("#modal-confirm .modal-title").html("Confirm Disable");
                $("#modal-confirm .modal-message").html("Are you sure you want to disable '"+name+"'? Only an administrator can undo this.");
                $("#modal-confirm form").prop("action", "/"+path+"/disable/" + id);
            });


            // ATTACH THE MODAL OVERLAY HANDLER
            $(document).on("click", ".confirmVendorBillOverlay", function() {

                var name = $(this).data('name');

                $("#modal-confirm .modal-title").html("Confirm "+name);
                $("#modal-confirm .modal-message").html('<strong>This overlay cannot be undone.</strong><br /><br />On successful completion, it will scan the dropbox for vendor bill documents and file them to their appropriate folder. You may also upload files after the fact, as the filer will run once per hour on its own.<hr /><div class="input-group"><div class="custom-file"><input type="file" class="custom-file-input" id="overlay" name="overlay"><label class="custom-file-label" for="overlay">No file selected...</label></div></div>').trigger("change");
                $("#modal-confirm form").prop("action", "/reports/vendor-bill-overlay");
            });
            {{-- END --}}


            {{-- LINE ITEM / TASK DIALOGS --}}
            @if(Route::currentRouteName() == 'viewProperty' || Route::currentRouteName() == 'editProperty' || Route::currentRouteName() == 'orderLineItems' || Route::currentRouteName() == 'tasks')

            // ATTACH THE MODAL CANCEL HANDLER
            $(document).on("click", ".confirmHold, .confirmRelease", function() {

                var type = null;
                if($(this).hasClass("confirmHold"))
                    type = 'Hold';
                if($(this).hasClass("confirmRelease"))
                    type = 'Release';

                var path = $(this).data('path');
                var name = $(this).data('name');
                var id = $(this).data('id');

                $("#modal-confirm .modal-title").html("Confirm "+type);
                $("#modal-confirm .modal-message").html("Are you sure you want to "+type.toLowerCase()+" '"+name+"'? <br /><br /><input type='text' class='form-control modal-comment' id='modal-comment' name='modal-comment' value='' style='width: 100%; display:none' />");

                if(type=='hold')
                    $("#modal-confirm .modal-comment").show().attr("placeholder", "Please enter a reason... [required]");
                else
                    $("#modal-confirm .modal-comment").show().attr("placeholder", "Please enter a reason...");
                $("#modal-confirm form").prop("action", path)
                $("#modal-confirm form :input").prop("disabled", false);
            });

            @endif


            {{-- ORDER DIALOGS --}}
            @if(Route::currentRouteName() == 'tasks' || Route::currentRouteName() == 'orders' || Route::currentRouteName() == 'editOrder' || Route::currentRouteName() == 'reviewOrder')

                // ATTACH THE MODAL CANCEL HANDLER
                $(document).on("click", ".confirmCancel", function() {

                    var path = $(this).data('path');
                    var name = $(this).data('name');
                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Cancellation");
                    $("#modal-confirm .modal-message").html("Are you sure you want to cancel '"+name+"'? This cannot be undone.");
                    $('.modal-message').append('<br /><br /><input type="text" class="form-control modal-comment" id="modal-comment" name="modal-comment" value="" style="width: 100%; display:none" />');
                    $("#modal-confirm .modal-comment").show().attr("placeholder", "Please enter a reason...");
                    $("#modal-confirm form").prop("action", "/"+path+"/cancel/" + id);
                });

            @endif

            @if(Route::currentRouteName() == 'tasks' || Route::currentRouteName() == 'orders' || Route::currentRouteName() == 'editOrder' || Route::currentRouteName() == 'amendOrder')

                // ATTACH THE MODAL REJECT HANDLER
                $(document).on("click", ".confirmReject", function() {

                    var path = $(this).data('path');
                    var name = $(this).data('name');
                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Rejection");

                    $("#modal-confirm .modal-message").html("Are you sure you want to reject '"+name+"'? This cannot be undone.");
                    $('#modal-confirm .modal-message').append('<br /><br /><input type="text" class="form-control modal-comment" id="modal-comment" name="modal-comment" value="" style="width: 100%; display:none" />');

                    $("#modal-confirm .modal-comment").show().attr("placeholder", "Please enter a reason...");
                    $("#modal-confirm form").prop("action", "/"+path+"/reject/" + id);
                });

            @endif

            @if(Route::currentRouteName() == 'tasks' || Route::currentRouteName() == 'orders')
                // ATTACH THE MODAL REJECT HANDLER
                $(document).on("click", ".confirmReassign", function() {

                    var path = $(this).data('path');
                    var name = $(this).data('name');
                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Reassignment");
                    $("#modal-confirm .modal-message").html("Are you sure you want to reassign '"+name+"'?");
                    $('.modal-message').append('<br /><br /><input type="hidden" id="property_id" name="property_id" value="" /><input id="order-search" name="order-search" style="width: 100% !important;" type="text" class="form-control" value="" placeholder="Start typing to find a new asset..."/>');

                    $("#modal-confirm .modal-comment").show().attr("placeholder", "Please enter a reason for reassignment...");
                    $("#modal-confirm form").prop("action", "/"+path+"/reassign/" + id);


                    var bloodhound = new Bloodhound({
                        datumTokenizer: Bloodhound.tokenizers.obj.whitespace('value'),
                        queryTokenizer: Bloodhound.tokenizers.whitespace,
                        prefetch: false,
                        remote: {
                            url: '/properties/search/%QUERY%',
                            wildcard: '%QUERY%'
                        }
                    });

                    $('#order-search').typeahead({
                        hint: true,
                        highlight: true,
                        autoselect: true,
                        minLength: 1
                    }, {
                        name: 'properties',
                        source: bloodhound,
                        limit: 7,
                        display: function (data) {
                            if(data.code)
                                return data.code;
                            else
                                return data.code_temp;
                        },
                        templates: {
                            header: [
                                '<div class="list-group search-results-dropdown">'
                            ],
                            pending: [
                                '<div class="list-group search-results-dropdown"><div class="list-group-item">Searching <i class="fas fa-spinner fa-pulse"></i></div></div>'
                            ],
                            suggestion: function (data) {
                                return '<div style="width: 100% !important; font-weight:normal; margin-top:-10px ! important;" class="list-group-item"><a href="#">' + data.code + ' / ' + data.code_temp + '<br />' + data.address + ', ' + data.city + ', ' + data.state + ' ' + data.zip + ', ' + data.county + '</a></div></div>';
                            },
                            notFound: function (data) {
                                $(".property_link").parent().hide();
                                return '<div class="list-group search-results-dropdown"><div class="list-group-item">No results.</div></div>';
                            },
                        }
                    });
                    $('#order-search').bind('typeahead:select', function (ev, asset) {
                        $("#property_id").val(asset.id);
                        $("#order-search").val(asset.code+" / "+asset.code_temp);
                    });

                    var path = $(this).data('path');
                    var name = $(this).data('name');
                    var id = $(this).data('id');


                });

            @endif
            {{-- END --}}


            {{-- PROPERTY DIALOGS --}}
            @if(Route::currentRouteName() == 'editProperty')

                // ATTACH THE MODAL DELETE HANDLER, BUT SEND AN AJAX REQUEST ON SUBMIT INSTEAD
                //     ASYNCHRONOUSLY DELETES PHOTOS ON THE BACKEND
                $(document).on("click", ".delete-photo", function() {
                    var name = $(this).data('name');
                    var path = $(this).data('path');
                    var id = $(this).data('id');
                    var image = $(this).parent();

                    $("#modal-confirm .modal-title").html("Confirm Delete");
                    $("#modal-confirm .modal-message").html("Are you sure you want to delete '"+name+"'? This cannot be undone.");
                    $("#modal-confirm form").prop("action", "/properties/edit/"+id+"/delete/image");

                    $("#modal-confirm button:submit").click(function(event){
                        event.preventDefault();
                        $.post( "/properties/edit/"+id+"/delete/image", {
                            _token: "{{ csrf_token() }}",
                            path: path,
                            name: name
                        })
                        .done(function() {
                            var div_name = name.replace(".", "_");
                            $('#'+ div_name).remove();
                            $('#modal-confirm').modal('hide');
                            //console.log("deleted '"+name+"'");
                        })
                        .fail(function() {
                            //console.log("error");
                        })
                        .always(function() {
                            //console.log("finished");
                        });
                    });
                });

                /////// Rotate photo on property photot tab
                $(document).on("click", ".rotate-photo", function() {

                    var name = $(this).data('name');
                    var path = $(this).data('path');
                    var id = $(this).data('id');
                    var degree =$(this).data('degree');


                    $.post( "/properties/edit/"+id+"/rotate/image", {
                        _token: "{{ csrf_token() }}",
                        path: path,
                        name: name,
                        degree: degree
                    })
                        .done(function() {
                            var div_name = name.replace(".", "_");
                            $('#'+ div_name).html('<a data-lightbox="photos" data-title="'+name+'" href="/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name+'&='+Math.floor(Math.random() * 10)+'" >'+
                                '<img class="img-thumbnail property-photo" src="/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name+'&='+Math.floor(Math.random() * 10)+'" />'+
                                '<a  class="btn btn-sm btn-danger" style="display: none;" ></a>\n' +
                                '                <div class="btn-group setting-photo">\n' +
                                '                    <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">\n' +
                                '                        <i class="fas fa-cog" data-toggle="tooltip"  data-original-title="Setting"></i>\n' +
                                '                    </button>\n' +
                                '                    <div class="dropdown-menu">\n' +
                                '                        <a data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'"  data-degree="90" class="dropdown-item rotate-photo" href="#">Rotate 90° Right</a>\n' +
                                '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-degree="270" class="dropdown-item rotate-photo" href="#">Rotate 90° Left</a>\n' +
                                '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-crop" class="dropdown-item crop-photo">Crop</a>\n' +
                                '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-confirm" class="dropdown-item revert-photo">Undo</a>\n' +
                                '                        <div class="dropdown-divider"></div>\n' +
                                '                        <a data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-confirm" class="dropdown-item delete-photo">Delete</a>\n' +
                                '                    </div>\n' +
                                '                </div>'+
                                '</a>'
                            );
                        })
                        .fail(function() {})
                        .always(function() {});

                });
                /////// end rotate photo

                //////  show modal with crop feature on property photo tab
                var $modal = $('#modal-crop');
                var image = document.getElementById('image-cropper');
                var cropper;

                $(document).on("click", ".crop-photo", function(){
                    var name = $(this).data('name');
                    var path = $(this).data('path');
                    var id = $(this).data('id');
                    $('#img-name').val(name);


                    image.src = '/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name;
                    $modal.modal('show');
                });

                $modal.on('shown.bs.modal', function () {
                    cropper = new Cropper(image, {
                        viewMode: 3,
                        preview: '.img-preview',
                        crop(event) {
                            $("#dataX").val(event.detail.x);
                            $("#dataY").val(event.detail.y);
                            $("#dataWidth").val(event.detail.width);
                            $("#dataHeight").val(event.detail.height);
                            $("#dataScaleX").val(event.detail.scaleX);
                            $("#dataScaleY").val(event.detail.scaleY);
                        },
                    });


                }).on('hidden.bs.modal', function () {
                    cropper.destroy();
                    cropper = null;
                });

                // /////// Crop photo on property photo tab
                $("#crop").click(function(){
                    var name = $('#img-name').val();
                    var path = $('.crop-photo').data('path');
                    var id = $('.crop-photo').data('id');

                    canvas = cropper.getCroppedCanvas({});

                    canvas.toBlob(function(blob) {
                        url = URL.createObjectURL(blob);
                        var reader = new FileReader();
                        reader.readAsDataURL(blob);
                        reader.onloadend = function() {
                            var base64data = reader.result;

                            $.post( "/properties/edit/"+id+"/crop/image", {
                                _token: $('meta[name="_token"]').attr('content'),
                                image: base64data,
                                name: name,
                                path: path,
                            })
                                .done(function(data) {
                                    var div_name = name.replace(".", "_");
                                    $('#'+ div_name).html('<a data-lightbox="photos" data-title="'+name+'" href="/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name+'&='+Math.floor(Math.random() * 10)+'" >'+
                                        '<img class="img-thumbnail property-photo" src="/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name+'&='+Math.floor(Math.random() * 10)+'" />'+
                                        '<a  class="btn btn-sm btn-danger" style="display: none;" ></a>\n' +
                                        '                <div class="btn-group setting-photo">\n' +
                                        '                    <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">\n' +
                                        '                        <i class="fas fa-cog" data-toggle="tooltip"  data-original-title="Setting"></i>\n' +
                                        '                    </button>\n' +
                                        '                    <div class="dropdown-menu">\n' +
                                        '                        <a data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'"  data-degree="90" class="dropdown-item rotate-photo" href="#">Rotate 90° Right</a>\n' +
                                        '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-degree="270" class="dropdown-item rotate-photo" href="#">Rotate 90° Left</a>\n' +
                                        '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-crop" class="dropdown-item crop-photo">Crop</a>\n' +
                                        '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-confirm" class="dropdown-item revert-photo">Undo</a>\n' +
                                        '                        <div class="dropdown-divider"></div>\n' +
                                        '                        <a data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-confirm" class="dropdown-item delete-photo">Delete</a>\n' +
                                        '                    </div>\n' +
                                        '                </div>'+
                                        '</a>'
                                    );
                                    $('#modal-crop').modal('hide');

                                })
                                .fail(function() {})
                                .always(function() {});
                        }
                    });
                });
                // end crop photo

                // start revert or undo  photo on property photo tab . it only undo rotate and crop, not works for delete
                $(document).on("click", ".revert-photo", function() {

                    var name = $(this).data('name');
                    var path = $(this).data('path');
                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Revert");
                    $("#modal-confirm .modal-message").html("Are you sure you want to undo changes to image '"+name+"'?");
                    $("#modal-confirm form").prop("action", "/properties/edit/"+id+"/revert/image");

                    $("#modal-confirm button:submit").click(function(event){
                        event.preventDefault();
                        $.post( "/properties/edit/"+id+"/revert/image", {
                            _token: "{{ csrf_token() }}",
                            path: path,
                            name: name
                        })
                            .done(function(data) {
                                var div_name = name.replace(".", "_");
                                $('#'+ div_name).html('<a data-lightbox="photos" data-title="'+name+'" href="/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name+'&='+Math.floor(Math.random() * 10)+'" >'+
                                    '<img class="img-thumbnail property-photo" src="/properties/view/'+id+'/image?_token={{ csrf_token() }}&name='+name+'&='+Math.floor(Math.random() * 10)+'" />'+
                                    '<a  class="btn btn-sm btn-danger" style="display: none;" ></a>\n' +
                                    '                <div class="btn-group setting-photo">\n' +
                                    '                    <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">\n' +
                                    '                        <i class="fas fa-cog" data-toggle="tooltip"  data-original-title="Setting"></i>\n' +
                                    '                    </button>\n' +
                                    '                    <div class="dropdown-menu">\n' +
                                    '                        <a data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'"  data-degree="90" class="dropdown-item rotate-photo" href="#">Rotate 90° Right</a>\n' +
                                    '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-degree="270" class="dropdown-item rotate-photo" href="#">Rotate 90° Left</a>\n' +
                                    '                        <a  data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-crop" class="dropdown-item crop-photo">Crop</a>\n' +
                                    '                        <div class="dropdown-divider"></div>\n' +
                                    '                        <a data-name="'+name+'" data-path="{{ "/Photos" }}" data-id="'+id+'" data-toggle="modal" href="#modal-confirm" class="dropdown-item delete-photo">Delete</a>\n' +
                                    '                    </div>\n' +
                                    '                </div>'+
                                    '</a>'
                                );
                                $('#modal-confirm').modal('hide');

                            })
                            .fail(function() {
                                //console.log("error");
                            })
                            .always(function() {
                                //console.log("finished");
                            });
                    });
                });
                // end revert/undo photo

                $(document).on("click", ".amenity-photo", function() {

                    var id = $(this).data('id');
                    var name = $(this).data('name');
                    var path = $(this).data('path');

                    $.post( "/properties/edit/"+id+"/showAmenity/image", {dataType: 'json'})
                        .done(function(data) {

                            $('#modal-submit').modal('toggle');
                            $('#modal-submit').modal('show');
                            $("#modal-submit .modal-title").html("Amenity Photos");
                            $("#modal-submit .modal-message").html("Which amenity is related to this photo?");
                            $("#modal-submit .modal-cont").html(data);
                            $("#amenity_photo_select").multiselect();
                            $("#modal-submit form").prop("action", "/properties/edit/"+id+"/submitAmenity/image");

                            $("#modal-submit button:submit").click(function(event){

                                var selectedAmenity = $('select#amenity_photo_select').val()


                                event.preventDefault();
                                $.post( "/properties/edit/"+id+"/submitAmenity/image", {
                                    _token: "{{ csrf_token() }}",
                                    selectedAmenity: selectedAmenity,


                                })
                                    .done(function(data) {

                                    })
                                    .fail(function() {
                                        //console.log("error");
                                    })
                                    .always(function() {
                                        //console.log("finished");
                                    });
                            });


                        })
                        .fail(function() {
                            //console.log("error");
                        })
                        .always(function() {
                            //console.log("finished");
                        });

                });


                // ATTACH THE MODAL DELETE HANDLER, BUT SEND AN AJAX REQUEST ON SUBMIT INSTEAD
                //     ASYNCHRONOUSLY DELETES BROCHURES ON THE BACKEND
                $(document).on("click", ".delete-brochure", function() {

                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Delete");
                    $("#modal-confirm .modal-message").html("Are you sure you want to delete 'brochure.pdf'? This cannot be undone.");
                    $("#modal-confirm form").prop("action", "/properties/edit/"+id+"/delete/brochure");
                    $('#modal-confirm').modal('show');

                    $("#modal-confirm button:submit").click(function(event){
                        event.preventDefault();
                        $.post("/properties/edit/"+id+"/delete/brochure", {
                            _token: "{{ csrf_token() }}"
                        })
                        .done(function () {
                            $(".delete-brochure").remove();
                            $(".download-brochure").remove();
                            $('#modal-confirm').modal('hide');
                            $(".marketing-actions").append('<a href="#" class="btn btn-sm btn-primary upload-brochure">Upload Brochure</a>');
                        })
                        .fail(function () {
                            //console.log("error");
                        })
                        .always(function () {
                            //console.log("finished");
                        });
                    });

                });

            @endif
            {{-- END --}}


            {{-- SUPER USER DIALOGS --}}
            @if(Auth::user()->hasRole('super-user') || (Auth::user()->can(['vendor_bills_restore']) || Auth::user()->can(['vendor_bills_delete'])) )

                // ATTACH THE MODAL RESTORE HANDLER
                $(document).on("click", ".confirmRestore", function() {

                    var path = $(this).data('path');
                    var name = $(this).data('name');
                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Restore");
                    $("#modal-confirm .modal-message").html("Are you sure you want to restore '"+name+"'?");
                    $("#modal-confirm form").prop("action", "/"+path+"/restore/" + id);
                });

                // ATTACH THE MODAL DELETE HANDLER
                $(document).on("click", ".confirmDelete", function() {

                    var path = $(this).data('path');
                    var name = $(this).data('name');
                    var id = $(this).data('id');

                    $("#modal-confirm .modal-title").html("Confirm Delete");
                    $("#modal-confirm .modal-message").html("Are you sure you want to delete '"+name+"'? This cannot be undone.");
                    $("#modal-confirm form").prop("action", "/"+path+"/delete/" + id);
                });

            @endif
            {{-- END --}}
        });
    </script>
@show
