package api2.provider

import api2.Address
import api2.ConnectionLocation
import api2.Provider

import com.raumobil.api2.enums.ConLocSubType
import com.raumobil.api2.enums.ConLocType
import com.raumobil.api2.enums.OSMAmenity
import com.raumobil.api2.exceptions.BadRequestExceptionLocaleMessage
import com.raumobil.api2.http.ConnectionLocationListResponse
import com.raumobil.api2.http.ConnectionLocationResponse
import com.raumobil.api2.interfaces.IPoiBbox
import com.raumobil.api2.interfaces.IPoiDetails
import com.raumobil.api2.utils.StringUtils
import com.raumobil.api2.utils.GeoHelper

import grails.plugin.cache.Cacheable

import org.grails.web.json.JSONObject

import com.vividsolutions.jts.geom.Point
import com.vividsolutions.jts.geom.Geometry
import org.springframework.context.MessageSource


/**
 * overpass requests https://overpass-api.de/
 */
class OverpassService extends AbstractProviderService implements IPoiBbox, IPoiDetails {
    // overpass api url
    static final String BASE_URL_OVERPASS = "https://overpass-api.de"
    static final String PATH_OVERPASS = "/api/interpreter"
    static MessageSource messageSource

    def requestService


    @Override
    Provider getProvider() {
        return Provider.get("OVERPASS")
    }


    /**
     * function that gets POIs within a bounding box from OSM.
     * example URL:
     http://localhost:8080/Poi/bbox?providerId=overpass&ignoreCache=true&minLat=48.885429865976306&minLng=8.331972022599416&maxLat=49.085288092239125&maxLng=8.580365782378038&providerParams=%7B%27type%27%3A%27station%27%2C%20%27subtype%27%3A%27taxi%27%7D
     * the requested URL should be like this:
     * http://overpass-api.de/api/interpreter?data=[out:json];node[%22amenity%22=%22taxi%22](48.885429865976306, 8.3319720225994160 ,49.085288092239125, 8.580365782378038);out;
     * @param minLng
     * @param minLat
     * @param maxLng
     * @param maxLat
     * @param providerParams
     * @param ignoreCache
     * @return
     */
    @Override
    @Cacheable(value="7d", condition={!ignoreCache})
    ConnectionLocationListResponse poiBbox(Double minLng, Double minLat, Double maxLng, Double maxLat, JSONObject providerParams, Boolean ignoreCache) {
        poiBboxProviderParamsValidation(providerParams)
        OSMAmenity osmAmenity = getOSMType(providerParams.type, providerParams.subtype)
        String data = "[out:json];node['amenity'='" + osmAmenity.osmAmenityName +"'](" +minLat  +","+ minLng +","+ maxLat +"," + maxLng +");out;"
        def result = requestService.getResponse(BASE_URL_OVERPASS , PATH_OVERPASS, ["data":data])
        List <ConnectionLocation> connectionLocations = []
        result.elements?.each {
            ConnectionLocation location = parseNode(it, osmAmenity)
            if(location){
                // add geopoints
                location.geometry = GeoHelper.createPoint(it.lon, it.lat)

                // add address
                location.address = createReverseGeocodedConnectionLocation(it.lon, it.lat, ignoreCache).address

                connectionLocations.add(location)
            }
        }

        return new ConnectionLocationListResponse(connectionLocations)
    }


    /**
     * function that graps the detail for a OSM point
     * @param item
     * @return
     */
    private ConnectionLocation parseNode(def item, OSMAmenity osmAmenity) {
        ConnectionLocation location = new ConnectionLocation()
        location.providerLocationId = item.id
        location.provider = provider
        location.extended = [:]
        location.type = osmAmenity.conLocType
        location.subType = osmAmenity.conLocSubType

        item.tags?.each{
            switch (it.key) {
                case "amenity":
                    break

                case "name":
                    location.name = it.value
                    break

                // address ...
                case "addr:street":
                    location.address.street = it.value
                    break
                case "addr:housenumber":
                    location.address.houseNumber = it.value
                    break
                case "addr:postcode":
                    location.address.postalCode = it.value
                    break
                case "addr:city":
                    location.address.city = it.value
                    break
                case "addr:suburb":
                    //location.address.su
                    break
                case "addr:country":
                    location.address.countryCode = it.value
                    break
                case "operator":
                    location.extendedTmp = ["operator":it.value]
                    break
//                case "wheelchair":
//                    location.extendedTmp = ["wheelchair":it.value]
//                    location.subType = ConLocSubType.HANDICAP
//                    break
                // parking specifics..
                case "park_ride":  // in extended rein .. nicht als subtype
                    location.type = ConLocType.PARKING
                    location.subType = ConLocSubType.PARK_AND_RIDE
                    break
                case "parking":
                    if (!pType?.trim()) {
                        location.type = ConLocType.PARKING
                        location.subType = ConLocSubType.CAR
                    }
                    break
                case "access":
                    // private parking(lot) --> unusable
                    if (it.value == 'private'){
                        return null
                    }
                    break
                case 'capacity:charging':
                    location.type = ConLocType.PARKING
                    location.subType = ConLocSubType.ELECTRO
                    break

                default:
                    location.extended.put(it.key, it.value)
            }
        }

        // add default name if none is given..
        if (!location.name?.trim()){
            location.name = osmAmenity.label
        }

        return location
    }


    @Override
    void poiBboxProviderParamsValidation(JSONObject providerParams) {
        if(!providerParams.type){
            throw new BadRequestExceptionLocaleMessage("default.blank.message",["type", "overpass"])
        }

//        try {
//            String type = StringUtils.camelcaseToUnderscore(providerParams.type).toUpperCase()
//            type as ConLocType
//        }catch(Exception){
//            throw new BadRequestExceptionLocaleMessage("invalid.providerparams.osm.type.invalid")
//        }
//
//        try {
//            String type = StringUtils.camelcaseToUnderscore(providerParams.subType).toUpperCase()
//            type as ConLocType
//        }catch(Exception){
//            throw new BadRequestExceptionLocaleMessage("invalid.providerparams.osm.type.invalid")
//        }

        if( providerParams.type && (providerParams.type instanceof Integer) ){
            throw new BadRequestExceptionLocaleMessage("invalid.providerparams.osm.type.string")
        }
    }


    /**
     * cacheable function
     * @param locationId
     * @param providerParams
     * @return
     */
    ConnectionLocationResponse poiDetails(String locationId = "", JSONObject providerParams, Boolean ignoreCache){
        if (!locationId?.trim()){
            throw new BadRequestExceptionLocaleMessage("default.blank.message",["locationId"])
        }

        ConnectionLocation location

        // get details for node from overpass
        def result = requestService.getResponse(BASE_URL_OVERPASS , PATH_OVERPASS, ["data":"[out:json];node(" + locationId + ");out;"])
        if(result){
            result.elements.each {
                location = parseNode(it)
            }
        }

        if (location){
            return new ConnectionLocationResponse(location)
        } else {
            return new ConnectionLocationResponse()
        }
    }


    void poiDetailsProviderParamsValidation(JSONObject providerParams){
    }


    /**
     *  function that gets the OSM name of a given type:
     *  it searches the tpe in list used by OSM.
     *  If the type is not mentioned in this list we check
     *  if there is another word defined in com/raumobil/api2/enums/ConLocSubType.groovy and used by OSM
     */
     private OSMAmenity getOSMType(String type, String subType) {
         try {
             // CamelCase => camel_case + uppercase
             type = StringUtils.camelcaseToUnderscore(type).toUpperCase()
             subType = StringUtils.camelcaseToUnderscore(subType).toUpperCase()

             OSMAmenity osmAmenity = OSMAmenity.values().find {
                 it.conLocType == type as ConLocType &&
                 it.conLocSubType == subType as ConLocSubType
             }
             return osmAmenity

         } catch(Exception e){
             throw new BadRequestExceptionLocaleMessage("invalid.providerparams.osm.type.invalid")
         }
     }
}