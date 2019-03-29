$(document).ready(function(){
    $('.materialboxed').materialbox();
    $('.modal').modal();
  });

function sendSearch() {
    let query = $('#searchQuery').val().trim();
    if(query.length >= 2){
        apretaste.send({
            'command':'CUBANET BUSCAR',
            'data':{'searchQuery' : query, 'isCategory':false}
        });
    }
    else
        M.toast({'html':'Minimo 2 caracteres'});
}