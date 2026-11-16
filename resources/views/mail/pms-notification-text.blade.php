{{ $headline }}

@if ($actorName)
From {!! $actorName !!}

@endif
@if ($detail)
{!! $detail !!}

@endif
@if ($actionUrl)
Open in PMS: {!! $actionUrl !!}
@endif
