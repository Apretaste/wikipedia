// submit on enter
$(document).ready(function () {
	$('#query').keypress(function (e) {
		if ((e.which && e.which == 13) || (e.keyCode && e.keyCode == 13)) submit();
	});
});

function submit()
{
	// get the query
	var query = $('#query').val();

	// perform a search
	wikisearch(query);
}

function wikisearch(query)
{
	// check the query is not empty
	if(query.length <= 3) {
		M.toast({html: 'Inserte más de 3 caracteres'});
		return false;
	}

	// send the request
	apretaste.send({
		command: "WIKIPEDIA", 
		data: {"query": query},
		redirect: true});
}