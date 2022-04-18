var comapp="ACCI";

function getFormattedDate(date) {
  var year = date.getFullYear();
  var month = (1 + date.getMonth()).toString();
  month = month.length > 1 ? month : '0' + month;
  var day = date.getDate().toString();
  day = day.length > 1 ? day : '0' + day;
  return year + "-" + month + "-" + day;
}

function getFormattedDate1(date) {
  var year = date.getFullYear();
  var month = (1 + date.getMonth()).toString();
  month = month.length > 1 ? month : '0' + month;
  var day = date.getDate().toString();
  day = day.length > 1 ? day : '0' + day;
  return year + "-" + month + "-" + "01";
}

function PerActive(){
	var tglbatas=''; 
	var output=''; 
		window.dhx4.ajax.post("util.php?mode=per",function(loader){
			//alert(loader.xmlDoc.responseText.trim());
			//return loader.xmlDoc.responseText;
			//var tglbatas = String(loader.xmlDoc.responseText);
			output = loader.xmlDoc.responseText;
		return output;
	});
}

function EdOk(tg){
		window.dhx4.ajax.post("util.php?mode=EdOk&tgl="+tg,function(loader){
			alert(tg);
			alert(loader.xmlDoc.responseText.trim());
			//return loader.xmlDoc.responseText;
			//var tglbatas = String(loader.xmlDoc.responseText);
			if(loader.xmlDoc.responseText.trim()=="OK"){
					return true;
			} else {
					return false;
			}
	});
}

