{{--
    Trix rich-text editor over a hidden input. The input id must be unique
    per field or every editor on the page writes into the first one.
--}}
@props(['name', 'id', 'value' => ''])

<input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}">
<trix-editor input="{{ $id }}" class="trix-content min-h-40 rounded border border-neutral-300 bg-white"></trix-editor>
