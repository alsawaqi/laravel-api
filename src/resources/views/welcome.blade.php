as
<br>

@Auth
{{ auth()->user()->id }}
@else
{{ 'Guest' }}
@endAuth