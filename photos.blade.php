<div class="row photo-container">
    @foreach($photos as $photo)
        <div class="col-md-3" id="{{ str_replace(".","_",urlencode($photo)) }}">
            <a data-lightbox="photos" data-title="{{ $photo }}" href="/properties/view/{{ $property->id }}/image?_token={{ csrf_token() }}&name={{ urlencode($photo) }}">
                <img class="img-thumbnail property-photo" id="{{ $property->id }}" src="/properties/view/{{ $property->id }}/image?_token={{ csrf_token() }}&name={{ urlencode($photo) }}" />
                @if(Route::currentRouteName() != 'viewProperty')
                    <a  class="btn btn-sm btn-danger" style="display: none;" ></a>
                    <div class="btn-group setting-photo">
                        <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-cog" data-toggle="tooltip"  data-original-title="Setting"></i>
                        </button>
                        <div class="dropdown-menu">
                            <a data-name="{{ $photo }}" data-path="{{ "/Photos" }}" data-id="{{ $property->id }}"  data-degree="90" class="dropdown-item rotate-photo" href="#">Rotate 90° Right</a>
                            <a  data-name="{{ $photo }}" data-path="{{ "/Photos" }}" data-id="{{ $property->id }}" data-degree="270" class="dropdown-item rotate-photo" href="#">Rotate 90° Left</a>
                            <a  data-name="{{ $photo }}" data-path="{{ "/Photos" }}" data-id="{{ $property->id }}" data-toggle="modal" href="#modal-crop" class="dropdown-item crop-photo">Crop</a>
                            <a data-name="{{ $photo }}" data-path="{{ "/Photos" }}" data-id="{{ $property->id }}"  class="dropdown-item amenity-photo" href="#">Assign to the amenity</a>
                            {{--                        <a  data-name="{{ $photo }}" data-path="{{ "/Photos" }}" data-id="{{ $property->id }}" data-toggle="modal" href="#modal-confirm" class="dropdown-item revert-photo">Revert</a>--}}
                            <div class="dropdown-divider"></div>
                            <a data-name="{{ $photo }}" data-path="{{ "/Photos" }}" data-id="{{ $property->id }}" data-toggle="modal" href="#modal-confirm" class="dropdown-item delete-photo">Delete</a>
                        </div>
                    </div>
                @endif
            </a>
        </div>
    @endforeach
</div>

{{--<select <select class="form-control" id="amenity_photo_select" multiple="multiple">--}}
{{--    <option>Dishwasher</option>--}}
{{--    <option>Washing Machine</option>--}}
{{--    <option>Relish</option>--}}
{{--</select>--}}

@if(Route::currentRouteName() == 'editProperty' && Auth::user()->can('photos_tab_edit'))
    <div class="row justify-content-center align-items-end">
        <div class="col-md-1">
            <a href="#" class="btn btn-secondary upload-photo">Upload Photos</a>
        </div>
    </div>
@endif

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.6/cropper.css" integrity="sha256-jKV9n9bkk/CTP8zbtEtnKaKf+ehRovOYeKoyfthwbC8=" crossorigin="anonymous" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.6/cropper.js" integrity="sha256-CgvH7sz3tHhkiVKh05kSUgG97YtzYNnWt6OXcmYzqHY=" crossorigin="anonymous"></script>
