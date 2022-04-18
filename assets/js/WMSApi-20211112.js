;(function (global, factory) {
  if (typeof define === 'function' && define.amd) {
    define(['axios', 'DateUtils'], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('axios'), require('DateUtils'))
  } else {
    if (!global.hasOwnProperty('axios')) {
      throw Error('axios is not loaded!');
    }
    if (!global.hasOwnProperty('DateUtils')) {
      throw Error('DateUtils is not defined!');
    }
    global.WMSApi = factory(global.axios, global.DateUtils);
  }
}(this, function (axios, DateUtils) {
  'use strict';

  let BASE_URL = './api'; // default path

  function setBaseUrl(newUrl) {
    BASE_URL = newUrl;
  }
  
  function dateToString(date) {
    if (date instanceof Date) {
      return DateUtils.toSqlDate(date);
    } else if (typeof date === 'string') {
      return date
    }
  }

  /**
   * Handles API-related errors.
   */
  class ApiError extends Error {
    constructor(message, origin = null) {
      super(message);
      this.name = 'ApiError';
      this.origin = origin;

      if (this.origin !== null) {
        if (this.origin.response) { // axios response error
          this.errorType = ApiError.TYPES.AXIOS_RESPONSE
        } else if (error.request) { // axios request error
          this.errorType = ApiError.TYPES.AXIOS_REQUEST
        } else {
          this.errorType = ApiError.TYPES.OTHER
        }
      } else {
        this.errorType = ApiError.TYPES.OTHER
      }
    }
  }

  ApiError.TYPES = Object.freeze({
    AXIOS_RESPONSE: 1,
    AXIOS_REQUEST: 2,
    OTHER: 3
  });

  /**
   * Handler for API-related errors.
   * @param {Object|Error} error error to handle
   * @throws {ApiError}
   */
  function errorHandler(error) {
    let errorMessage;
    if (error.response) {
      if (error.response.data instanceof Object) {
        errorMessage = error.response.data.msg;
      } else {
        errorMessage = error.response.data;
      }
    } else if (error.request) {
      errorMessage = 'request error';
      console.error(error.request);
    } else {
      errorMessage = error.message || error.toString();
      console.error(error);
    }
    throw new ApiError(errorMessage, error);
  }

  const auth = {
    /**
     * Changes the current user's password.
     * @return {Promise<T | never>}
     */
    changeSelfPassword(currentPassword, newPassword, newPasswordConfirm) {
      const url = `${BASE_URL}/auth/ChangeSelfPassword.php`;
      return axios.post(url, {
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirm: newPasswordConfirm
      })
        .then(response => response.data.msg || response.data)
        .catch(errorHandler)
    },
    /**
     *
     * @return {Promise<T | never>}
     */
    fetchAllAllowedSubplants() {
      return axios.get(`${BASE_URL}/auth/FindAllAllowedSubplants.php?mode=json`)
        .then(response => response.data.data)
        .catch(errorHandler)
    },

    changeUserRole(newRole) {
      return axios.post(`${BASE_URL}/auth/ChangeRole.php`, { new_role: newRole })
        .then(response => response.data)
        .catch(errorHandler)
    },

    getCurrentUserDetails() {
      return axios.get(`${BASE_URL}/auth/GetUserDetails.php?mode=json`)
        .then(response => response.data.data)
        .catch(errorHandler)
    }
  };

  /**
   * Get the information of a location based on its ID (area name, subplant, etc.).
   * Can also be used to check if the location exists in the system.
   * @param {string} locationId location to check.
   * @returns {Promise<T>}
   */
  function getLocationInfo(locationId) {
    let url = `${BASE_URL}/location/GetLocationInfo.php?location_id=${locationId}`;
    return axios.get(url)
      .then(response => {
        return response.data.data
      })
      .catch(error => {
        errorHandler(error)
      })
  }

  /**
   * Get the pallets that are present in a certain location.
   * @param {string} locationId
   * @return {Promise<T | never>}
   */
  function fetchPalletsByLocationId(locationId) {
    let url = `${BASE_URL}/location/FindPalletsByLocationId.php?mode=json&location_id=${locationId}`;
    return axios.get(url)
      .then(response => {
        return response.data.data
      })
      .catch(error => {
        errorHandler(error)
      })
  }

  /**
   * Get the information of a pallet based on its ID.
   * Can also be used to check if the pallet exists in the system.
   * @param {string} palletNo ID of the pallet to check.
   * @returns {Promise<T>}
   */
  function getPalletInfo(palletNo) {
    let url = `${BASE_URL}/stock/GetPalletInfo.php?pallet_no=${palletNo}`;
    return axios.get(url)
      .then(response => {
        return response.data.data
      })
      .catch(error => {
        errorHandler(error)
      })
  }

  function fetchPalletsByAreaSummary(warehouseIds) {
    let url = `${BASE_URL}/stock/GetStockSummaryByLocation.php?mode=json`;
    if (warehouseIds !== 'all') {
      url += `&warehouse_id=${warehouseIds}`
    }
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchPalletsByAreaDetailsSummary(warehouseId, areaNo) {
    const url = `${BASE_URL}/stock/GetStockDetailsByLocation.php?mode=json&area_no=${areaNo}&warehouse_id=${warehouseId}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchStockSummaryByMotif(subplant) {
    const url = `${BASE_URL}/stock/GetStockSummaryByMotif.php?mode=json&subplant=${subplant}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchStockDetailsByMotif(selectedSubplant, motifIds = []) {
    const url = `${BASE_URL}/stock/GetStockDetailsByMotif.php?mode=json`;
    return axios.post(url, { motif_ids: motifIds, subplant: selectedSubplant })
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchPalletsAvailableForSalesByGroup(selectedSubplant, selectedLocationSubplant, motifSpecs = []) {
    const url = `${BASE_URL}/sales/FindPalletsAvailableForSalesWithoutLocationByMotifGroup.php?mode=json`;
    return axios.post(url, { motif_specs: motifSpecs, subplant: selectedSubplant, location_subplant: selectedLocationSubplant })
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchMotifGroupsWithRimpil(selectedSubplant, motifSpecs = [], withRimpil = false) {
    let url = `${BASE_URL}/sales/FindMotifGroupsWithRimpil.php?mode=json`;

    const params = {motif_specs: motifSpecs, subplant: selectedSubplant, with_rimpil: withRimpil};
    if (motifSpecs.length === 0) {
      delete params.motif_specs
    }

    return axios.post(url, params)
      .then(response => {
        // combine same motif IDs together
        const data = {};
        const qualities = [];
        response.data.data.forEach(motifGroup => {
          const key = `${motifGroup.location_subplant}_${motifGroup.production_subplant}_${motifGroup.motif_group_name}`;
          if (!data.hasOwnProperty(key)) {
            data[key] = {
              location_subplant: motifGroup.location_subplant,
              production_subplant: motifGroup.production_subplant,
              motif_group_id: motifGroup.motif_group_id,
              motif_dimension: motifGroup.motif_dimension,
              motif_group_name: motifGroup.motif_group_name,
              pallet_count: {},
              quantity: {}
            }
          }

          const refGroup = data[key];
          const quality = motifGroup.quality;
          // collect quality
          if (!qualities.includes(quality)) {
            qualities.push(quality)
          }

          // collect quantity
          if (!refGroup.quantity.hasOwnProperty(quality)) {
            if (withRimpil) {
              refGroup.quantity[quality] = {normal: 0, rimpil: 0}
            } else {
              refGroup.quantity[quality] = 0;
            }
          }

          if (withRimpil) {
            const isRimpil = motifGroup.is_rimpil;
            if (isRimpil) {
              refGroup.quantity[quality].rimpil += motifGroup.quantity;
            } else {
              refGroup.quantity[quality].normal += motifGroup.quantity;
            }
          } else {
            refGroup.quantity[quality] += motifGroup.quantity;
          }

          // collect pallet count
          if (!refGroup.pallet_count.hasOwnProperty(quality)) {
            if (withRimpil) {
              refGroup.pallet_count[quality] = {normal: 0, rimpil: 0}
            } else {
              refGroup.pallet_count[quality] = 0;
            }
          }

          if (withRimpil) {
            const isRimpil = motifGroup.is_rimpil;
            if (isRimpil) {
              refGroup.pallet_count[quality].rimpil += motifGroup.pallet_count;
            } else {
              refGroup.pallet_count[quality].normal += motifGroup.pallet_count;
            }
          } else {
            refGroup.pallet_count[quality] += motifGroup.pallet_count;
          }
        });

        // flatten result to array
        const result = [];
        Object.keys(data).forEach(key => {
          const refGroup = data[key];

          // zero out nonexistent qualities
          qualities.forEach(quality => {
            if (!refGroup.quantity.hasOwnProperty(quality)) {
              if (withRimpil) {
                refGroup.quantity[quality] = {normal: 0, rimpil: 0}
              } else {
                refGroup.quantity[quality] = 0
              }
            }

            if (!refGroup.pallet_count.hasOwnProperty(quality)) {
              if (withRimpil) {
                refGroup.pallet_count[quality] = {normal: 0, rimpil: 0}
              } else {
                refGroup.pallet_count[quality] = 0
              }
            }
          });

          result.push(refGroup)
        });

        return {
          data: result,
          qualities
        };
      })
      .catch(error => {
        errorHandler(error)
      })
  }

  function fetchStockMutationSummaryByMotif(selectedSubplant, fromDate, toDate, motifIds = []) {
    const s_fromDate = dateToString(fromDate);
    const s_toDate = dateToString(toDate);
    
    const url = `${BASE_URL}/stock/GetMutationSummaryByMotif.php`;
    return axios.post(url, {
      subplant: selectedSubplant,
      date_from: s_fromDate,
      date_to: s_toDate,
      motif_ids: motifIds
    })
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchStockMutationSummaryByMotifSizeShading(selectedSubplant, fromDate, toDate, motifIds = []) {
    const s_fromDate = dateToString(fromDate);
    const s_toDate = dateToString(toDate);
    
    const url = `${BASE_URL}/stock/GetMutationSummaryByMotifSizeShading.php`;
    return axios.post(url, {
      subplant: selectedSubplant,
      date_from: s_fromDate,
      date_to: s_toDate,
      motif_ids: motifIds
    })
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchStockMutationSummaryMetadata() {
    let url = `${BASE_URL}/stock/GetMutationSummaryMetadata.php`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  /**
   *
   * @param {string} mutationType
   * @param {string} subplant
   * @param {string|Date} fromDate
   * @param {string|Date} toDate
   * @param {string|Array<string>} motifId
   * @return {Promise<{records: Array, motifs: Object}>}
   */
  function fetchStockMutationSummaryDetails(mutationType, subplant, fromDate, toDate, motifId) {
    const s_fromDate = dateToString(fromDate);
    const s_toDate = dateToString(toDate);

    const url = `${BASE_URL}/stock/GetMutationSummaryDetails.php`;
    return axios.post(url, {
      mutation_type: mutationType,
      subplant: subplant,
      date_from: s_fromDate,
      date_to: s_toDate,
      motif_id: motifId
    })
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  /**
   * Add pallet(s) to a location.
   * @param {Array} palletNos pallets to add/update.
   * @param {string} locationId new location id.
   * @returns {Promise<T | never>} axios Promise object
   */
  function addPalletsToLocation(palletNos, locationId) {
    let url = `${BASE_URL}/location/AddPalletsToLocation.php`;
    return axios.post(url, {pallet_nos: palletNos.join(','), location_id: locationId})
      .then(response => {
        return response.data
      })
      .catch(error => {
        errorHandler(error)
      })
  }

  /**
   * Removes pallets from location.
   *
   * @param {Array} palletNos pallets to remove.
   * @param {string} reason reason for removal
   * @return {Promise<T | never>}
   */
  function removePalletsFromLocation(palletNos, reason) {
    let url = `${BASE_URL}/location/RemovePalletsFromLocation.php`;
    return axios.post(url, {pallet_nos: palletNos.join(','), reason})
      .then(response => {
        return response.data
      })
      .catch(error => {
        errorHandler(error)
      })
  }

  function fetchPalletsWithoutLocation(selectedSubplant) {
    let url = `${BASE_URL}/stock/GetPalletsWithoutLocation.php`
    if (selectedSubplant !== 'all') {
      url += `?subplant=${selectedSubplant}`
    }

    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchPalletsByLine(selectedSubplant, areaCode, lineNo) {
    let url = `${BASE_URL}/location/FindPalletsByLine.php?mode=json&subplant=${selectedSubplant}&area_code=${areaCode}`;
    if (lineNo !== 'all') {
      url += `&line_no=${lineNo}`
    }

    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchAllLocations() {
    let url = `${BASE_URL}/location/FindAllLocations.php?mode=json`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function createNewPalletsToOtherPlantRequest(palletNos, destinationPlant) {
    let url = `${BASE_URL}/stock/CreateNewPalletsToOtherPlantRequest.php`;
    return axios.post(url, {
      pallet_nos: palletNos,
      destination_plant: destinationPlant
    })
      .then(response => response.data.data) // this returns the transaction header.
      .catch(errorHandler)
  }

  function updatePalletsToOtherPlantRequest(txnId, palletNos) {
    let url = `${BASE_URL}/stock/UpdatePalletsToOtherPlantRequest.php`;
    return axios.post(url, {
      txn_Id: txnId,
      pallet_nos: palletNos
    })
      .then(response => response.data.data) // this returns the transaction header.
      .catch(errorHandler)
  }

  /**
   * Updates the status of pallets to other plant request.
   * @param txnId {string} transaction ID of the request
   * @param status {string} new status of the request
   * @param [shippingDetails] {string|null}
   *  shipping details related to the request. Only required if status === 'S'.
   *  otherwise, it will be ignored.
   * @return {Promise<T>}
   */
  function setPalletsToOtherPlantRequestStatus(txnId, status, shippingDetails = null) {
    let url = `${BASE_URL}/stock/SetPalletsToOtherPlantRequestStatus.php`;
    const params = { txn_Id: txnId, status: status };
    if (status === 'S') {
      if (shippingDetails === null) {
        throw Error('Missing shipping details for shipped transaction id!')
      } else {
        params.shipping_details = shippingDetails
      }
    }
    return axios.post(url, params)
      .then(response => response.data.data) // this returns the transaction header.
      .catch(errorHandler)
  }

  function getPalletsToOtherPlantRequestDetails(txnId) {
    let url = `${BASE_URL}/ship/GetPalletsToOtherPlantDetails.php?txn_id=${txnId}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function fetchPalletsAvailableForBlocking(subplant, productionDateFrom, productionDateTo, line, motifName, shift, quality = null) {
    const s_productionDateFrom = dateToString(productionDateFrom);
    const s_productionDateTo = dateToString(productionDateTo);
    
    let url = `${BASE_URL}/qa/GetPalletsAvailableForBlocking.php?`;
    url += `subplant=${subplant}`;
    url += `&production_date_from=${s_productionDateFrom}`;
    url += `&production_date_to=${s_productionDateTo}`;
    url += `&line=${line}`;
    url += `&creator_shift=${shift}`;
    url += `&motif_name=${motifName}`;
    if (quality) {
      url += `&quality=${quality}`
    }

    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }
  
  function getDowngradeRequests(subplant, dateFrom, dateTo, reason, status, type) {
    const s_dateFrom = dateToString(dateFrom);
    const s_dateTo = dateToString(dateTo);

    let url = `${BASE_URL}/qa/GetDowngradeRequests.php?`;
    url += `subplant=${subplant}`;
    url += `&date_from=${s_dateFrom}`;
    url += `&date_to=${s_dateTo}`;
    url += `&reason=${reason}`;
    url += `&status=${status}`;
    url += `&type=${type}`;

    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }
  
  function getDowngradeDetails(downgradeId) {
    let url = `${BASE_URL}/qa/GetDowngradeDetails.php?id=${downgradeId}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function createDowngradeRequest(subplant, palletNos, downgradeType, reason) {
    let url = `${BASE_URL}/qa/CreateDowngradeRequest.php`;
    return axios.post(url, {
      subplant: subplant,
      pallet_nos: palletNos,
      type: downgradeType,
      reason: reason
    })
      .then(response => response.data.data)
      .catch(errorHandler);
  }


  function updateDowngradeRequest(downgradeId, newReason, palletsToAdd, palletsToRemove = []) {
    let url = `${BASE_URL}/qa/UpdateDowngradeRequest.php`;
    return axios.post(url, {
      id: downgradeId,
      reason: newReason,
      pallets_to_add: palletsToAdd,
      pallets_to_remove: palletsToRemove
    })
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function approveDowngradeRequest(downgradeId, isApproved, reason = null) {
    let url = `${BASE_URL}/qa/ApproveDowngradeRequest.php`;
    const data = {
      id: downgradeId,
      is_approved: isApproved
    };
    if (!isApproved) {
      data.reason = reason;
    }
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function cancelDowngradeRequest(downgradeId, reason) {
    let url = `${BASE_URL}/qa/CancelDowngradeRequest.php`;
    const data = {
      id: downgradeId,
      reason: reason
    };
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function getBlockQuantityList(subplant, tiperpt) {
    let url = `${BASE_URL}/location/GetBlockQuantityList.php?`;
    url += `subplant=${subplant}`;
    url += `&tiperpt=${tiperpt}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function getBlockQuantityListDetail(customer) {
    let url = `${BASE_URL}/location/GetBlockQuantityListDetail.php`;
    return axios.post(url, {
      customer: customer
    })
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function fetchItemsAvailableForBlocking(subplant, quality, size, shading, motif, lokasi) {
    let url = `${BASE_URL}/location/GetItemsAvailableForBlocking.php?`;
    url += `subplant=${subplant}`;
    url += `&quality=${quality}`;
    url += `&size=${size}`;
    url += `&shading=${shading}`;
    url += `&motif=${motif}`;
    url += `&lokasi=${lokasi}`;

    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

function getBlockQuantityRequests(subplant, fromDate, toDate, status) {
    const s_dateFrom = dateToString(fromDate);
    const s_dateTo = dateToString(toDate);
    let url = `${BASE_URL}/location/GetBlockQuantityRequests.php?`;
    url += `subplant=${subplant}`;
    url += `&date_from=${s_dateFrom}`;
    url += `&date_to=${s_dateTo}`;
    url += `&status=${status}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function getBlockQuantityDetails(orderId) {
    let url = `${BASE_URL}/location/GetBlockQuantityDetails.php?id=${orderId}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function createBlockQuantityRequests(requestedSubplant, customer, orderTargetDate, keterangan, palletNoS, qtyS) {
    const s_orderTargetDate = dateToString(orderTargetDate);

    let url = `${BASE_URL}/location/CreateBlockQuantityRequests.php`;
    return axios.post(url, {
      subplant: requestedSubplant,
      customer: customer,
      order_target_date: s_orderTargetDate,
      keterangan: keterangan,
      pallet_no_s: palletNoS,
      qty_s: qtyS
    })
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function updateBlockQuantityRequests(orderId, requestedSubplant, customer, orderTargetDate, keterangan, palletNoS, qtyS) {
    const s_orderTargetDate = dateToString(orderTargetDate);

    let url = `${BASE_URL}/location/UpdateBlockQuantityRequests.php`;
    return axios.post(url, {
      id: orderId,
      subplant: requestedSubplant,
      customer: customer,
      order_target_date: s_orderTargetDate,
      keterangan: keterangan,
      pallet_no_s: palletNoS,
      qty_s: qtyS
    })
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function cancelBlockQuantityRequests(orderId, reason) {
    let url = `${BASE_URL}/location/CancelBlockQuantityRequests.php`;
    const data = {
      id: orderId,
      reason: reason
    };
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function completeBlockQuantityRequests(orderId, reason) {
    let url = `${BASE_URL}/location/CompleteBlockQuantityRequests.php`;
    const data = {
      id: orderId,
      reason: reason
    };
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler);
  }
  /**
   * Get available downgrade reasons from the service.
   * @param {boolean} [enabledOnly] whether to show only enabled reasons.
   * @return {Promise<Array<{ id: number, reason: string, created_at: string, updated_at: string, is_disabled: boolean }>>}
   */
  function getAvailableDowngradeReasons(enabledOnly = false) {
    let url = `${BASE_URL}/qa/GetAvailableDowngradeReasons.php`;
    if (enabledOnly) {
      url += '?is_disabled=false'
    }
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  /**
   * Create new downgrade reason.
   * @param {string} reason new downgrade reason
   * @return {Promise<{ id: number, reason: string, created_at: string, updated_at: string, is_disabled: boolean }>}
   */
  function createNewDowngradeReason(reason) {
    let url = `${BASE_URL}/qa/CreateNewDowngradeReason.php`;
    const data = { reason: reason };
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  /**
   * Update status of existing downgrade reason
   * @param {number} reasonId id of the reason to update.
   * @param {boolean} isDisabled whether the reason should be visible/invisible to the available list.
   * @return {Promise<{ id: number, reason: string, created_at: string, updated_at: string, is_disabled: boolean }>}
   */
  function updateExistingDowngradeReasonStatus(reasonId, isDisabled) {
    let url = `${BASE_URL}/qa/UpdateExistingDowngradeReasonStatus.php`;
    const data = { id: reasonId, is_disabled: isDisabled };
    return axios.patch(url, data)
      .then(response => response.data.data)
      .catch(errorHandler);
  }

  function getSalesDanProduksiBulanan(subplant, tiperpt, tahunrpt, gruprpt) {
    let url = `${BASE_URL}/location/GetSalesDanProduksiBulanan.php?`;
    url += `subplant=${subplant}`;
    url += `&tiperpt=${tiperpt}`;
    url += `&tahunrpt=${tahunrpt}`;
    url += `&gruprpt=${gruprpt}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function getSalesDanProduksiBulananTipe(subplant, tiperpt, tahunrpt, gruprpt) {
    let url = `${BASE_URL}/location/GetSalesDanProduksiBulananTipe.php?`;
    url += `subplant=${subplant}`;
    url += `&tiperpt=${tiperpt}`;
    url += `&tahunrpt=${tahunrpt}`;
    url += `&gruprpt=${gruprpt}`;
    return axios.get(url)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function getLapAging(locationsubplant, dimension, isrimpil) {
    let url = `${BASE_URL}/location/GetLapAging.php?`;
    const data = { 
      locationsubplant: locationsubplant, 
      dimension: dimension, 
      isrimpil: isrimpil
    };
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  function getLapAgingDetail(locationsubplant, dimension, isrimpil, motifname, size, shading, quality) {
    let url = `${BASE_URL}/location/GetLapAgingDetail.php?`;
    const data = { 
      locationsubplant: locationsubplant, 
      dimension: dimension, 
      isrimpil: isrimpil,
      motifname: motifname,
      size: size,
      shading: shading,
      quality: quality
    };
    return axios.post(url, data)
      .then(response => response.data.data)
      .catch(errorHandler)
  }

  const location = {
    fetchPalletsByAreaSummary, fetchPalletsByAreaDetailsSummary,
    fetchPalletsByLine, fetchPalletsByLocationId,
    getLocationInfo, fetchAllLocations,
    addPalletsToLocation, removePalletsFromLocation,
    getBlockQuantityList, getBlockQuantityListDetail, fetchItemsAvailableForBlocking, getBlockQuantityRequests, getBlockQuantityDetails, 
    createBlockQuantityRequests, updateBlockQuantityRequests, cancelBlockQuantityRequests, completeBlockQuantityRequests, getSalesDanProduksiBulanan, getSalesDanProduksiBulananTipe, getLapAging, getLapAgingDetail
  };
  const stock = {
    getPalletInfo,
    fetchStockSummaryByMotif, fetchStockDetailsByMotif,
    fetchMotifGroups: fetchMotifGroupsWithRimpil,
    fetchSKUsAvailableForSalesByGroup: fetchPalletsAvailableForSalesByGroup,
    fetchStockMutationSummaryByMotif, fetchStockMutationSummaryByMotifSizeShading, fetchStockMutationSummaryMetadata,
    fetchStockMutationSummaryDetails,
    fetchPalletsWithoutLocation
  };

  const qa = {
    fetchPalletsAvailableForBlocking, getDowngradeRequests, getDowngradeDetails,
    createDowngradeRequest, cancelDowngradeRequest, approveDowngradeRequest, updateDowngradeRequest,
    getAvailableDowngradeReasons, createNewDowngradeReason, updateExistingDowngradeReasonStatus
  };

  const ship = {
    getPalletsToOtherPlantRequestDetails, createNewPalletsToOtherPlantRequest,
    updatePalletsToOtherPlantRequest, setPalletsToOtherPlantRequestStatus
  };

  return {
    BASE_URL, auth, stock, location, ship, qa,
    setBaseUrl,
    ApiError
  }
}));
