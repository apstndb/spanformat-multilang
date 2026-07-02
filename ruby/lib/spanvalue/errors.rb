# frozen_string_literal: true

module Spanvalue
  class SpanValueError < StandardError; end

  class MalformedWireError < SpanValueError; end

  class UnknownTypeError < SpanValueError; end

  class MismatchedFieldsError < SpanValueError; end

  class EmptyTypeFQNError < SpanValueError; end

  class UnexpectedComplexValueKindError < SpanValueError; end

  class EmptyNullStringError < SpanValueError; end

  class TypeMismatchError < SpanValueError; end
end
