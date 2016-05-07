<div class="panel event-listing">
    <div class="panel-heading">
        <h3 class="panel-title">
            {{ $panel_title }}
        </h3>
    </div>
    <div class="panel-body">
        <div class="row">
            @foreach($events as $event)
                <div class="col-md-12 event">
                    <div class="panel widget">
                        <div class="table-layout nm">
                            <div class="col-xs-4 event_flyer">
                                <img src="{{$event->images->first()['image_path'] ? URL::to($event->images->first()['image_path']) : 'http://placehold.it/200'}}" >
                            </div>
                            <div class="col-xs-8 valign-middle">
                                <div class="panel-body">
                                    <h5 class="ellipsis semibold mt0 mb5">
                                        <a title="{{{$event->title}}}" href="{{$event->event_url}}">{{{$event->title}}}</a>
                                    </h5>
                                    <p class="ellipsis text-muted mb5"><i class="ico-clock mr5"></i> {{{ $event->start_date->toDayDateTimeString() }}}</p>
                                    <p class="text-muted nm"><i class="ico-location2 mr5"></i> {{{$event->venue_name}}}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>